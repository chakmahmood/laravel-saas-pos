<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Store lifecycle policy (Checkpoint 6).
 *
 * A store with business history is never physically deleted through the API;
 * it is archived/deactivated via `stores.is_active`. Deactivation must keep all
 * history (orders, payments, balances, ledger) and must block new transactions.
 */
class StoreLifecycleTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    public function test_deactivating_a_store_preserves_business_history(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '5.000');

        $order = Order::factory()->for($store, 'store')->create();
        Payment::factory()->create([
            'store_id' => $store->id,
            'order_id' => $order->id,
            'cash_session_id' => null,
        ]);
        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'type' => StockMovementType::OPENING->value,
            'quantity' => '5.000',
        ]);

        $orders = $store->orders()->count();
        $payments = $store->payments()->count();
        $balances = $store->stockBalances()->count();
        $movements = $store->stockMovements()->count();

        $store->update(['is_active' => false]);

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'is_active' => false]);
        $this->assertSame($orders, $store->orders()->count());
        $this->assertSame($payments, $store->payments()->count());
        $this->assertSame($balances, $store->stockBalances()->count());
        $this->assertSame($movements, $store->stockMovements()->count());
        $this->assertGreaterThan(0, $orders);
        $this->assertGreaterThan(0, $movements);

        unset($owner);
    }

    public function test_inactive_store_cannot_start_new_transactions(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $store->update(['is_active' => false]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_physical_store_deletion_is_not_exposed(): void
    {
        // No store resource route exists.
        $this->deleteJson('/api/stores/1')->assertNotFound();

        // The current-store route exists (GET/PUT) but deleting it is not allowed.
        $this->deleteJson('/api/current-store')->assertStatus(405);
    }
}
