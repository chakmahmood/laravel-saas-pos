<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Transaction history reads (list + detail) that the POS history page uses.
 * Order/payment writes and idempotency are covered elsewhere.
 */
class OrderHistoryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function makeItem(Store $store): Item
    {
        return Item::factory()->for($store, 'store')->create([
            'name' => 'Item '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 10000,
            'unit' => 'pcs',
            'is_active' => true,
        ]);
    }

    private function createOrder(Store $store, string $token, array $overrides = []): int
    {
        $payload = ['items' => [['item_id' => $this->makeItem($store)->id, 'quantity' => 1]]]
            + $overrides;

        return (int) $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', $payload)
            ->assertCreated()
            ->json('data.id');
    }

    public function test_index_only_returns_orders_of_the_active_store(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);

        $this->createOrder($storeA, $tokenA);
        $this->createOrder($storeA, $tokenA);
        $this->createOrder($storeB, $tokenB);

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_index_filters_by_status_and_search(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->createOrder($store, $token);
        $paidId = $this->createOrder($store, $token);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$paidId.'/fulfillment', ['fulfillment_status' => FulfillmentStatus::PROCESSING->value])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?fulfillment_status=processing')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paidId);

        $orderNumber = Order::query()->findOrFail($paidId)->order_number;

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?search='.$orderNumber)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order_number', $orderNumber);
    }

    public function test_index_validates_pagination_and_date_filters(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?payment_status=not-a-status')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_status');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?date_from=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_order_detail_exposes_server_remaining_amount(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);

        $orderId = $this->createOrder($store, $token);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.total_amount', 10000)
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.remaining_amount', 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 4000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 4000)
            ->assertJsonPath('data.remaining_amount', 6000)
            ->assertJsonPath('data.payment_status', PaymentStatus::PARTIALLY_PAID->value);
    }

    public function test_order_detail_is_tenant_isolated(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $foreign = Order::factory()->for($storeB, 'store')->create();

        $tokenA = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/orders/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_a_cashier_can_read_history(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);
        $this->createOrder($store, $token);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
