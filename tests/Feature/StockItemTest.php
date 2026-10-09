<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Foundation behaviour of `items.tracks_stock`.
 *
 * The flag defaults to false so existing catalog items never start feeding the
 * inventory ledger implicitly.
 */
class StockItemTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_items_do_not_track_stock_by_default(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $item = Item::factory()->for($store, 'store')->create();

        $this->assertFalse($item->refresh()->tracks_stock);
        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'tracks_stock' => 0,
        ]);
    }

    public function test_tracks_stock_is_cast_to_boolean(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $tracked = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);
        $untracked = Item::factory()->for($store, 'store')->create(['tracks_stock' => false]);

        $this->assertTrue($tracked->refresh()->tracks_stock);
        $this->assertFalse($untracked->refresh()->tracks_stock);
    }

    public function test_untracked_item_has_no_stock_relations_rows(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $item = Item::factory()->for($store, 'store')->create();

        $this->assertSame(0, $item->stockBalances()->count());
        $this->assertSame(0, $item->stockMovements()->count());
    }
}
