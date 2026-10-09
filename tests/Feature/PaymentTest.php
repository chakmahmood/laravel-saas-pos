<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PaymentTest extends TestCase
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

        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_payment_endpoints_require_authentication(): void
    {
        $this->getJson('/api/orders/1/payments')->assertUnauthorized();
        $this->postJson('/api/orders/1/payments', ['payment_method' => 'cash', 'amount' => 1000])->assertUnauthorized();
        $this->getJson('/api/payments/1')->assertUnauthorized();
        $this->postJson('/api/payments/1/void', [])->assertUnauthorized();
    }

    public function test_full_payment_marks_order_paid(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 10000)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment_method', 'cash');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 10000,
            'payment_status' => PaymentStatus::PAID->value,
        ]);
    }

    public function test_partial_payments_update_status(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 4000,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 4000,
            'payment_status' => PaymentStatus::PARTIALLY_PAID->value,
        ]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'bank_transfer',
                'amount' => 6000,
                'reference_number' => 'TRX-BANK-1',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 10000,
            'payment_status' => PaymentStatus::PAID->value,
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderId.'/payments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_overpayment_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 6000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 6000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 6000,
            'payment_status' => PaymentStatus::PARTIALLY_PAID->value,
        ]);
    }

    public function test_amount_must_be_positive(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_payment_for_another_tenant_order_is_rejected(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);

        $orderB = $this->makeOrderId($storeB, $tokenB);

        $this->withHeaders($this->bearer($tokenA))
            ->postJson('/api/orders/'.$orderB.'/payments', [
                'payment_method' => 'cash',
                'amount' => 5000,
            ])
            ->assertNotFound();
    }

    public function test_cashier_can_record_but_cannot_void_payments(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $orderId = $this->makeOrderId($store, $token, 10000);

        $payment = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $paymentId = $payment->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', ['reason' => 'x'])
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'completed']);
    }

    public function test_owner_can_void_a_payment_and_status_is_recomputed(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payment = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $paymentId = $payment->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', ['reason' => 'Salah input'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.void_reason', 'Salah input');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'voided',
            'voided_by' => $owner->id,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
    }

    public function test_voided_payment_no_longer_counts_toward_the_balance(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $payment = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $paymentId = $payment->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', [])
            ->assertOk();

        // Full balance is available again.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
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

    public function test_payment_on_cancelled_order_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$orderId.'/fulfillment', ['fulfillment_status' => 'cancelled'])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_cancelled');
    }

    public function test_cancelling_a_paid_order_is_rejected_until_payment_is_voided(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$orderId.'/fulfillment', ['fulfillment_status' => 'cancelled'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_conflict');
    }

    public function test_payment_show_is_tenant_isolated(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);

        $orderB = $this->makeOrderId($storeB, $tokenB);

        $payment = $this->withHeaders($this->bearer($tokenB))
            ->postJson('/api/orders/'.$orderB.'/payments', [
                'payment_method' => 'cash',
                'amount' => 5000,
            ])
            ->assertCreated();

        $paymentId = $payment->json('data.id');

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/payments/'.$paymentId)
            ->assertNotFound();

        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/payments/'.$paymentId)
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId);
    }
}
