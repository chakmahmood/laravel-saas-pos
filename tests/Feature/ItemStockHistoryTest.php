<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ItemStockHistoryTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    public function test_item_with_movements_cannot_be_deleted(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);

        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'type' => StockMovementType::OPENING->value,
            'quantity' => '5.000',
        ]);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$item->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'item_has_stock_history');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_item_with_balance_cannot_be_deleted(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '5.000');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$item->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'item_has_stock_history');

        $this->assertDatabaseHas('items', ['id' => $item->id]);
    }

    public function test_item_without_stock_history_is_still_deletable(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$item->id)
            ->assertOk();

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_non_tracked_item_is_deletable(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$item->id)
            ->assertOk();
    }
}
