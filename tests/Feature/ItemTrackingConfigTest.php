<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ItemTrackingConfigTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Item '.fake()->unique()->numerify('####'),
            'type' => 'product',
            'selling_price' => 10000,
            'unit' => 'pcs',
        ], $overrides);
    }

    public function test_creating_an_item_defaults_to_not_tracking_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.tracks_stock', false);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'tracks_stock' => 0]);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_owner_can_create_a_stock_tracked_item(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->payload(['tracks_stock' => true]))
            ->assertCreated()
            ->assertJsonPath('data.tracks_stock', true);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'tracks_stock' => 1]);
    }

    public function test_enabling_tracking_does_not_create_inventory_rows(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->payload(['tracks_stock' => true]))
            ->assertCreated();

        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_owner_can_enable_tracking_on_update(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => true])
            ->assertOk()
            ->assertJsonPath('data.tracks_stock', true);

        $this->assertTrue($item->refresh()->tracks_stock);
    }

    public function test_non_inventory_store_cannot_enable_tracking_on_create(): void
    {
        [$owner, $store] = $this->nonInventoryStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->payload(['tracks_stock' => true]))
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_non_inventory_store_cannot_enable_tracking_on_update(): void
    {
        [$owner, $store] = $this->nonInventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => true])
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->assertFalse($item->refresh()->tracks_stock);
    }

    public function test_cannot_disable_tracking_when_a_balance_exists(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '5.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'item_inventory_in_use');

        $this->assertTrue($item->refresh()->tracks_stock);
    }

    public function test_cannot_disable_tracking_when_movement_history_exists(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'type' => StockMovementType::OPENING->value,
            'quantity' => '1.000',
        ]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'item_inventory_in_use');

        $this->assertTrue($item->refresh()->tracks_stock);
    }

    public function test_can_disable_tracking_when_there_is_no_inventory_history(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => false])
            ->assertOk()
            ->assertJsonPath('data.tracks_stock', false);

        $this->assertFalse($item->refresh()->tracks_stock);
    }

    public function test_cashier_cannot_change_tracking(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => false])
            ->assertForbidden();
    }

    public function test_ambiguous_tracking_value_is_rejected(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['tracks_stock' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tracks_stock');
    }
}
