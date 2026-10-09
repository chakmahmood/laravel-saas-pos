<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockBalanceApiTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    public function test_stock_balance_endpoints_require_authentication(): void
    {
        $this->getJson('/api/stock/balances')->assertUnauthorized();
        $this->getJson('/api/items/1/stock')->assertUnauthorized();
    }

    public function test_index_only_returns_balances_of_the_current_store(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        $itemA = $this->trackedItem($storeA);
        $this->setStock($storeA, $itemA, '10.000');

        [, $storeB] = $this->inventoryStore();
        $itemB = $this->trackedItem($storeB);
        $this->setStock($storeB, $itemB, '99.000');

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $itemA->id)
            ->assertJsonPath('data.0.quantity_on_hand', '10.000');
    }

    public function test_get_does_not_create_a_balance_row(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$item->id.'/stock')
            ->assertOk()
            ->assertJsonPath('data.tracks_stock', true)
            ->assertJsonCount(0, 'data.balances');

        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_available_quantity_is_computed_exactly(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $balance = $this->setStock($store, $item, '10.000', '3.250');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances')
            ->assertOk()
            ->assertJsonPath('data.0.id', $balance->id)
            ->assertJsonPath('data.0.quantity_on_hand', '10.000')
            ->assertJsonPath('data.0.quantity_reserved', '3.250')
            ->assertJsonPath('data.0.quantity_available', '6.750');
    }

    public function test_index_filters_by_item_and_location_without_leaking_foreign_data(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $itemA = $this->trackedItem($store);
        $itemB = $this->trackedItem($store);
        $this->setStock($store, $itemA, '1.000');
        $this->setStock($store, $itemB, '2.000');

        [, $storeB] = $this->inventoryStore();
        $foreignItem = $this->trackedItem($storeB);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances?item_id='.$itemA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $itemA->id);

        // A foreign item id simply yields no rows (never another store's data).
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances?item_id='.$foreignItem->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_paginates(): void
    {
        [$owner, $store] = $this->inventoryStore();

        foreach (range(1, 3) as $i) {
            $item = $this->trackedItem($store);
            $this->setStock($store, $item, (string) $i.'.000');
        }

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_item_stock_returns_balances_for_a_tracked_item(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '8.000', '1.000');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$item->id.'/stock')
            ->assertOk()
            ->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.tracks_stock', true)
            ->assertJsonCount(1, 'data.balances')
            ->assertJsonPath('data.balances.0.quantity_available', '7.000');
    }

    public function test_item_stock_reports_non_tracked_items_explicitly(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store, ['tracks_stock' => false]);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$item->id.'/stock')
            ->assertStatus(409)
            ->assertJsonPath('code', 'inventory_item_not_tracked');
    }

    public function test_item_stock_hides_foreign_items(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        [, $storeB] = $this->inventoryStore();
        $itemB = $this->trackedItem($storeB);

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$itemB->id.'/stock')
            ->assertNotFound();
    }
}
