<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockBalanceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_balance_is_tied_to_store_location_and_item(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);

        $balance = StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => 2,
        ]);

        $this->assertTrue($balance->store->is($store));
        $this->assertTrue($balance->stockLocation->is($location));
        $this->assertTrue($balance->item->is($item));
        $this->assertSame(1, $store->stockBalances()->count());
    }

    public function test_balance_is_unique_per_location_and_item(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
        ]);

        $this->expectException(QueryException::class);

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
        ]);
    }

    public function test_same_item_may_have_a_balance_per_location(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);
        $locationA = StockLocation::factory()->for($store, 'store')->create();
        $locationB = StockLocation::factory()->for($store, 'store')->create();

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $locationA->id,
            'item_id' => $item->id,
        ]);
        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $locationB->id,
            'item_id' => $item->id,
        ]);

        $this->assertSame(2, $item->stockBalances()->count());
    }

    public function test_balance_quantity_uses_three_decimal_precision(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $balance = StockBalance::factory()->forStore($store)->create([
            'quantity_on_hand' => '12.345',
            'quantity_reserved' => '0.125',
        ]);

        $balance->refresh();

        $this->assertSame('12.345', $balance->quantity_on_hand);
        $this->assertSame('0.125', $balance->quantity_reserved);
    }

    public function test_balance_defaults_to_zero(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $balance = StockBalance::factory()->forStore($store)->create();

        $this->assertSame('0.000', $balance->refresh()->quantity_on_hand);
        $this->assertSame('0.000', $balance->quantity_reserved);
    }

    public function test_balance_foreign_key_rejects_a_missing_location(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $item = Item::factory()->for($store, 'store')->create();

        $this->expectException(QueryException::class);

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => 999999,
            'item_id' => $item->id,
        ]);
    }

    public function test_balance_foreign_key_rejects_a_missing_item(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $location = StockLocation::factory()->for($store, 'store')->create();

        $this->expectException(QueryException::class);

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => 999999,
        ]);
    }
}
