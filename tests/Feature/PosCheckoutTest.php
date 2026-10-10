<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\Store;
use App\Models\User;
use App\Services\StockLocationProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * End-to-end coverage for the POS checkout flow the frontend uses:
 *
 *   POST /api/orders                      -> create order (server-side totals)
 *   POST /api/orders/{order}/payments     -> record the payment
 *
 * The store is always resolved from the token; the client never supplies it.
 */
class PosCheckoutTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(Store $store, array $overrides = []): Item
    {
        return Item::factory()->for($store, 'store')->create(array_merge([
            'name' => 'Kopi '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 18000,
            'unit' => 'pcs',
            'is_active' => true,
        ], $overrides));
    }

    private function createOrderId(Store $store, string $token, int $quantity = 1): int
    {
        $item = $this->makeItem($store);

        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => $quantity]]])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/orders', ['items' => []])->assertUnauthorized();
        $this->postJson('/api/orders/1/payments', ['payment_method' => 'cash', 'amount' => 1000])
            ->assertUnauthorized();
    }

    public function test_pos_checkout_creates_the_order_then_records_payment(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);

        $item = $this->makeItem($store, ['selling_price' => 18000]);
        $orderNumber = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 2]]])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 36000)
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID->value)
            ->json('data.order_number');

        $orderId = (int) Order::query()->where('order_number', $orderNumber)->value('id');

        // Payment is paid against the server-computed total.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 36000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value)
            ->assertJsonPath('data.paid_amount', 36000)
            ->assertJsonPath('data.fulfillment_status', 'pending');
    }

    public function test_cash_payment_without_an_open_shift_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $orderId = $this->createOrderId($store, $token);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 18000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_required');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
    }

    public function test_non_cash_payment_does_not_require_a_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $orderId = $this->createOrderId($store, $token);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'bank_transfer',
                'amount' => 18000,
                'reference_number' => 'TRF-1',
            ])
            ->assertCreated();
    }

    public function test_client_supplied_prices_and_totals_are_ignored_at_checkout(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $item = $this->makeItem($store, ['selling_price' => 25000]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1],
                ],
                'total_amount' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 25000)
            ->assertJsonPath('data.items.0.unit_price', 25000);
    }

    public function test_a_cashier_can_complete_a_checkout_with_an_open_shift(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);
        $this->openShiftFor($cashier, $store);

        $orderId = $this->createOrderId($store, $token);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 18000,
            ])
            ->assertCreated();
    }

    public function test_overpayment_is_rejected_so_a_retry_cannot_double_charge(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);

        $orderId = $this->createOrderId($store, $token);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 18000,
            ])
            ->assertCreated();

        // A second full payment on the same order exceeds the remaining balance.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 18000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'paid_amount' => 18000,
            'payment_status' => PaymentStatus::PAID->value,
        ]);
    }

    public function test_checkout_rejects_an_item_from_another_tenant(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $foreignItem = $this->makeItem($storeB);

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $foreignItem->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.item_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_inventory_checkout_reserves_stock_without_a_premature_decrement(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

        $item = $this->makeItem($store, ['tracks_stock' => true]);
        $location = $store->stockLocations()->where('is_default', true)->firstOrFail();

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'quantity_on_hand' => '10.000',
            'quantity_reserved' => '0.000',
        ]);

        $token = $this->issueToken($owner, $store);
        $this->openShiftFor($owner, $store);

        $orderId = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 2]]])
            ->assertCreated()
            ->json('data.id');

        // Reserving, not selling: on-hand is unchanged.
        $balance = StockBalance::query()->where('item_id', $item->id)->firstOrFail();
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('2.000', $balance->quantity_reserved);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash',
                'amount' => 18000 * 2,
            ])
            ->assertCreated();

        // Payment must not move physical stock either.
        $balance->refresh();
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('2.000', $balance->quantity_reserved);
    }
}
