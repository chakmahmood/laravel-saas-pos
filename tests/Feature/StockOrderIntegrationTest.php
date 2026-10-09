<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\FulfillmentStatus;
use App\Enums\ItemType;
use App\Enums\PaymentRecordStatus;
use App\Enums\StockMovementType;
use App\Enums\StoreRole;
use App\Exceptions\OrderConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StockLedgerService;
use App\Services\StockLocationProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockOrderIntegrationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @return array{0: User, 1: Store}
     */
    private function inventoryStore(): array
    {
        [$owner, $store] = $this->createOwnerWithStore();

        app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

        return [$owner, $store];
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function nonInventoryStore(): array
    {
        $owner = User::factory()->create();
        $store = $this->createStore($owner, ['business_type' => BusinessType::LAUNDRY->value]);
        $this->attachMember($owner, $store, StoreRole::OWNER->value, true);

        return [$owner, $store];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trackedItem(Store $store, array $overrides = []): Item
    {
        return Item::factory()->for($store, 'store')->create(array_merge([
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 10000,
            'tracks_stock' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function setStock(Store $store, Item $item, string $onHand, string $reserved = '0'): StockBalance
    {
        $location = $store->stockLocations()->where('is_default', true)->firstOrFail();

        return StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
        ]);
    }

    private function defaultLocationId(Store $store): int
    {
        return (int) $store->stockLocations()->where('is_default', true)->value('id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createOrder(Store $store, User $owner, array $lines): Order
    {
        return app(OrderService::class)->create($store, $owner, ['items' => $lines]);
    }

    public function test_non_inventory_order_still_works(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);

        $order = $this->createOrder($store, $owner, [
            ['item_id' => $item->id, 'quantity' => 2],
        ]);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'store_id' => $store->id]);
        $this->assertNull($order->refresh()->stock_location_id);
        $this->assertNull($order->stock_committed_at);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_item_without_tracking_creates_no_reservation_or_movement(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);

        $order = $this->createOrder($store, $owner, [
            ['item_id' => $item->id, 'quantity' => 5],
        ]);

        $this->assertSame(
            0,
            $order->stockMovements()->where('type', StockMovementType::RESERVATION->value)->count(),
        );
    }

    public function test_store_without_inventory_support_rejects_tracked_item(): void
    {
        [$owner, $store] = $this->nonInventoryStore();
        $item = $this->trackedItem($store);

        try {
            $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1]]);
            $this->fail('Expected inventory_not_supported.');
        } catch (OrderConflictException $exception) {
            $this->assertSame('inventory_not_supported', $exception->errorCode());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_order_reserves_without_reducing_on_hand(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [
            ['item_id' => $item->id, 'quantity' => 2],
        ]);

        $order->refresh();
        $balance->refresh();

        $this->assertSame($this->defaultLocationId($store), (int) $order->stock_location_id);
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('2.000', $balance->quantity_reserved);

        $this->assertSame(1, $order->stockMovements()
            ->where('type', StockMovementType::RESERVATION->value)
            ->count());
    }

    public function test_insufficient_stock_rolls_back_the_entire_order(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '1.000');

        try {
            $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);
            $this->fail('Expected insufficient_stock.');
        } catch (OrderConflictException $exception) {
            $this->assertSame('insufficient_stock', $exception->errorCode());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $balance->refresh();
        $this->assertSame('1.000', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
    }

    public function test_multi_item_order_has_no_partial_changes_when_one_item_is_short(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $itemA = $this->trackedItem($store);
        $itemB = $this->trackedItem($store);
        $balanceA = $this->setStock($store, $itemA, '10.000');
        $balanceB = $this->setStock($store, $itemB, '1.000');

        try {
            $this->createOrder($store, $owner, [
                ['item_id' => $itemA->id, 'quantity' => 3],
                ['item_id' => $itemB->id, 'quantity' => 2],
            ]);
            $this->fail('Expected insufficient_stock.');
        } catch (OrderConflictException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('10.000', $balanceA->refresh()->quantity_on_hand);
        $this->assertSame('0.000', $balanceA->quantity_reserved);
        $this->assertSame('1.000', $balanceB->refresh()->quantity_on_hand);
        $this->assertSame('0.000', $balanceB->quantity_reserved);
    }

    public function test_order_with_tracked_item_requires_a_default_location(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        // No default location is provisioned for this store.
        $item = $this->trackedItem($store);

        try {
            $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1]]);
            $this->fail('Expected stock_location_unavailable.');
        } catch (OrderConflictException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_default_location_of_another_store_is_never_used(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        // Store A has no default location; store B (provisioned) has one.
        [, $storeB] = $this->inventoryStore();

        $itemA = $this->trackedItem($storeA);

        try {
            $this->createOrder($storeA, $ownerA, [['item_id' => $itemA->id, 'quantity' => 1]]);
            $this->fail('Expected stock_location_unavailable.');
        } catch (OrderConflictException $exception) {
            $this->assertSame('stock_location_unavailable', $exception->errorCode());
            $this->assertDatabaseCount('orders', 0);
        }

        $this->assertNotNull($storeB->stockLocations()->where('is_default', true)->first());
    }

    public function test_order_line_referencing_a_foreign_item_is_rejected(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        [, $storeB] = $this->inventoryStore();

        $foreignItem = $this->trackedItem($storeB);

        $order = Order::factory()->for($storeA, 'store')->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_id' => $foreignItem->id,
            'quantity' => 1,
        ]);

        try {
            app(StockLedgerService::class)->reserveForOrder($storeA, $order, $ownerA);
            $this->fail('Expected stock_tenant_mismatch.');
        } catch (OrderConflictException) {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_foreign_balance_is_not_used_across_stores(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        $itemA = $this->trackedItem($storeA);

        [, $storeB] = $this->inventoryStore();
        $itemB = $this->trackedItem($storeB);
        // Plenty of stock, but for another store/item.
        $this->setStock($storeB, $itemB, '100.000');

        try {
            $this->createOrder($storeA, $ownerA, [['item_id' => $itemA->id, 'quantity' => 1]]);
            $this->fail('Expected insufficient_stock.');
        } catch (OrderConflictException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_completing_an_order_reduces_on_hand_and_reserved(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);

        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::PROCESSING, null, $owner);
        $completed = app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::COMPLETED, null, $owner);

        $balance->refresh();

        $this->assertSame('8.000', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
        $this->assertNotNull($completed->stock_committed_at);

        $types = $completed->stockMovements()->pluck('type')->map(fn ($t) => $t->value)->all();
        $this->assertContains(StockMovementType::RESERVATION->value, $types);
        $this->assertContains(StockMovementType::SALE_OUT->value, $types);
        $this->assertContains(StockMovementType::RESERVATION_RELEASE->value, $types);
    }

    public function test_commit_is_idempotent(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);
        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::PROCESSING, null, $owner);
        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::COMPLETED, null, $owner);

        // Retry the commit directly.
        app(StockLedgerService::class)->commitForOrder($order->refresh(), $owner);

        $balance->refresh();
        $this->assertSame('8.000', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::SALE_OUT->value)->count());
        $this->assertSame(1, $order->stockMovements()->where('type', StockMovementType::RESERVATION_RELEASE->value)->count());
    }

    public function test_cancelling_before_commit_releases_reservation_only(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);

        $cancelled = app(OrderService::class)->changeFulfillment(
            $order,
            FulfillmentStatus::CANCELLED,
            'Salah input',
            $owner,
        );

        $balance->refresh();

        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
        $this->assertNull($cancelled->stock_committed_at);
        $this->assertSame(1, $cancelled->stockMovements()
            ->where('type', StockMovementType::RESERVATION_RELEASE->value)
            ->count());
        $this->assertSame(0, $cancelled->stockMovements()
            ->where('type', StockMovementType::SALE_OUT->value)
            ->count());
    }

    public function test_release_is_idempotent(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);
        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::CANCELLED, null, $owner);

        // Retry the release directly.
        app(StockLedgerService::class)->releaseForOrder($order->refresh(), $owner);

        $balance->refresh();
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
        $this->assertSame(1, $order->stockMovements()
            ->where('type', StockMovementType::RESERVATION_RELEASE->value)
            ->count());
    }

    public function test_non_inventory_order_completes_and_cancels_without_inventory_errors(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);

        $completed = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1]]);
        $completedOrder = app(OrderService::class)->changeFulfillment(
            $completed,
            FulfillmentStatus::PROCESSING,
            null,
            $owner,
        );
        $completedOrder = app(OrderService::class)->changeFulfillment(
            $completedOrder,
            FulfillmentStatus::COMPLETED,
            null,
            $owner,
        );

        $cancelled = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1]]);
        $cancelledOrder = app(OrderService::class)->changeFulfillment(
            $cancelled,
            FulfillmentStatus::CANCELLED,
            null,
            $owner,
        );

        $this->assertNull($completedOrder->stock_committed_at);
        $this->assertNull($cancelledOrder->stock_committed_at);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_voiding_a_payment_does_not_change_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 2]]);

        $payment = app(PaymentService::class)->record($order, $owner, [
            'payment_method' => 'bank_transfer',
            'amount' => $order->total_amount,
        ]);

        $movementsBefore = $order->stockMovements()->count();

        app(PaymentService::class)->void($payment, $owner, 'Batal');

        $balance->refresh();
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('2.000', $balance->quantity_reserved);
        $this->assertSame($movementsBefore, $order->refresh()->stockMovements()->count());
        $this->assertSame(
            PaymentRecordStatus::VOIDED,
            $payment->refresh()->status,
        );
    }

    public function test_movement_write_failure_rolls_back_the_balance_change(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1]]);
        $orderItem = $order->items()->firstOrFail();

        // Occupy the idempotency key the commit will use, forcing the movement
        // append to fail AFTER the balance has been reduced.
        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $order->stock_location_id,
            'item_id' => $item->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'type' => StockMovementType::SALE_OUT->value,
            'quantity' => 1,
            'idempotency_key' => 'order:'.$order->id.':item:'.$orderItem->id.':sale',
        ]);

        try {
            app(StockLedgerService::class)->commitForOrder($order->refresh(), $owner);
            $this->fail('Expected a unique constraint violation.');
        } catch (QueryException) {
            // expected: the transaction must roll back the balance change.
        }

        $balance->refresh();
        $this->assertSame('10.000', $balance->quantity_on_hand);
        $this->assertSame('1.000', $balance->quantity_reserved);
    }

    public function test_fractional_quantities_are_processed_without_float_drift(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store, ['unit' => 'kg']);
        $balance = $this->setStock($store, $item, '10.000');

        $order = $this->createOrder($store, $owner, [['item_id' => $item->id, 'quantity' => 1.125]]);
        $this->assertSame('1.125', $balance->refresh()->quantity_reserved);

        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::PROCESSING, null, $owner);
        app(OrderService::class)->changeFulfillment($order, FulfillmentStatus::COMPLETED, null, $owner);

        $balance->refresh();
        $this->assertSame('8.875', $balance->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
    }

    public function test_order_creation_api_returns_409_on_insufficient_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '0.000');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_api_reserves_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '5.000');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->assertSame('1.000', $balance->refresh()->quantity_reserved);
        $this->assertSame('5.000', $balance->quantity_on_hand);
    }
}
