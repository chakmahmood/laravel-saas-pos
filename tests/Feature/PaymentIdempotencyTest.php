<?php

namespace Tests\Feature;

use App\Enums\CashSessionStatus;
use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Idempotent payment recording.
 *
 * As with orders, SQLite cannot run true parallel requests; these tests prove
 * the deterministic invariant the order row lock protects. Real concurrency is
 * proven by `tests/Concurrency/run.php` on MySQL.
 */
class PaymentIdempotencyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function makeOrderId(Store $store, string $token, int $price = 10000): int
    {
        $item = Item::factory()->for($store, 'store')->create([
            'name' => 'Item '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => $price,
            'unit' => 'pcs',
            'is_active' => true,
        ]);

        return (int) $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_replaying_the_same_payment_key_returns_the_same_payment(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payload = [
            'idempotency_key' => 'pay-abc',
            'payment_method' => 'cash',
            'amount' => 10000,
        ];

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 10000,
            'payment_status' => PaymentStatus::PAID->value,
        ]);
    }

    public function test_same_payment_key_with_a_different_amount_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-conflict',
                'payment_method' => 'cash',
                'amount' => 4000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-conflict',
                'payment_method' => 'cash',
                'amount' => 6000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 4000,
            'payment_status' => PaymentStatus::PARTIALLY_PAID->value,
        ]);
    }

    public function test_payment_key_is_scoped_per_store(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();
        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);
        $this->openShiftFor($ownerA, $storeA);
        $this->openShiftFor($ownerB, $storeB);

        $orderA = $this->makeOrderId($storeA, $tokenA);
        $orderB = $this->makeOrderId($storeB, $tokenB);

        $payload = fn (): array => [
            'idempotency_key' => 'shared-pay-key',
            'payment_method' => 'cash',
            'amount' => 10000,
        ];

        $this->withHeaders($this->bearer($tokenA))
            ->postJson('/api/orders/'.$orderA.'/payments', $payload())
            ->assertCreated();

        $this->withHeaders($this->bearer($tokenB))
            ->postJson('/api/orders/'.$orderB.'/payments', $payload())
            ->assertCreated();

        $this->assertSame(1, Payment::query()->where('store_id', $storeA->id)->count());
        $this->assertSame(1, Payment::query()->where('store_id', $storeB->id)->count());
    }

    public function test_replaying_a_voided_payment_returns_the_voided_record(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payload = [
            'idempotency_key' => 'pay-void-replay',
            'payment_method' => 'cash',
            'amount' => 10000,
        ];

        $paymentId = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', ['reason' => 'Salah input'])
            ->assertOk();

        // Replaying the same key returns the existing (now voided) record; it
        // does not create a second payment and does not silently re-pay.
        $replay = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'voided');

        $this->assertSame($paymentId, $replay->json('data.id'));
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
    }

    public function test_replaying_a_cash_payment_after_the_shift_closed_returns_the_original(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $session = $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payload = [
            'idempotency_key' => 'pay-shift-close',
            'payment_method' => 'cash',
            'amount' => 10000,
        ];

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        // Close the shift directly (no cash-session lifecycle in this scope).
        $session->forceFill([
            'status' => CashSessionStatus::CLOSED->value,
            'open_guard' => null,
            'closed_at' => now(),
        ])->save();

        // A genuine retry of the same request must still resolve to the original
        // payment instead of failing with `cash_session_required`.
        $replay = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        $this->assertSame($created->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
    }

    public function test_a_failed_payment_leaves_the_order_unpaid_and_can_be_retried(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        // Overpayment is refused: the order stays unpaid with no payment rows.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-too-much',
                'payment_method' => 'cash',
                'amount' => 20000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, Payment::query()->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);

        // A corrected attempt (new key) succeeds.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-corrected',
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 10000,
            'payment_status' => PaymentStatus::PAID->value,
        ]);
    }

    public function test_a_paid_order_cannot_be_paid_again_with_a_new_key(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-first',
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'idempotency_key' => 'pay-second',
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
    }

    public function test_a_cashier_can_replay_their_own_payment_idempotently(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);
        $this->openShiftFor($cashier, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payload = [
            'idempotency_key' => 'cashier-pay',
            'payment_method' => 'cash',
            'amount' => 10000,
        ];

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Payment::query()->where('order_id', $orderId)->count());
    }
}
