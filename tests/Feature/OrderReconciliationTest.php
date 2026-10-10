<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Checkout reconciliation: read-only lookup of an order by the client
 * idempotency key, scoped strictly to the active store.
 */
class OrderReconciliationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function makeItem(Store $store, int $price = 10000): Item
    {
        return Item::factory()->for($store, 'store')->create([
            'name' => 'Item '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => $price,
            'unit' => 'pcs',
            'is_active' => true,
        ]);
    }

    private function createOrderWithKey(Store $store, string $token, string $key, int $price = 10000): int
    {
        return (int) $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => $key,
                'items' => [['item_id' => $this->makeItem($store, $price)->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_reconcile_requires_authentication(): void
    {
        $this->getJson('/api/orders/reconcile?idempotency_key=x')->assertUnauthorized();
    }

    public function test_reconcile_requires_a_current_store(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key=x')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_reconcile_validates_the_key(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_reconcile_finds_the_order_by_key_without_creating_data(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $orderId = $this->createOrderWithKey($store, $token, 'recon-key-1');

        $ordersBefore = Order::query()->where('store_id', $store->id)->count();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key=recon-key-1')
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.remaining_amount', 10000);

        $this->assertSame($ordersBefore, Order::query()->where('store_id', $store->id)->count());
    }

    public function test_reconcile_after_replay_returns_the_same_order(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $orderId = $this->createOrderWithKey($store, $token, 'recon-replay');

        // A replay of the same order request is idempotent.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => 'recon-replay',
                'items' => [['item_id' => $this->makeItem($store)->id, 'quantity' => 1]],
            ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key=recon-replay')
            ->assertOk()
            ->assertJsonPath('data.id', $orderId);

        $this->assertSame(1, Order::query()->where('store_id', $store->id)->count());
    }

    public function test_reconcile_reflects_a_recorded_payment(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);

        $orderId = $this->createOrderWithKey($store, $token, 'recon-paid');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 10000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key=recon-paid')
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value)
            ->assertJsonPath('data.paid_amount', 10000)
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_reconcile_unknown_key_is_a_consistent_not_found(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/reconcile?idempotency_key=does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('code', 'order_not_found');
    }

    public function test_reconcile_key_of_another_store_does_not_leak(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);

        // Store B owns an order under the shared key.
        $this->createOrderWithKey($storeB, $tokenB, 'shared-recon-key');

        // Store A must not see it.
        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/orders/reconcile?idempotency_key=shared-recon-key')
            ->assertNotFound()
            ->assertJsonPath('code', 'order_not_found');

        // And store B still resolves its own order.
        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/orders/reconcile?idempotency_key=shared-recon-key')
            ->assertOk()
            ->assertJsonPath('data.total_amount', 10000);
    }

    public function test_reconcile_does_not_create_orders_on_missing_keys(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->bearer($token))
                ->getJson('/api/orders/reconcile?idempotency_key=missing-'.$i)
                ->assertNotFound();
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
