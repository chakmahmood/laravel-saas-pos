<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_movement_type_is_cast_to_enum(): void
    {
        $movement = StockMovement::factory()
            ->ofType(StockMovementType::PURCHASE_IN)
            ->create();

        $this->assertSame(StockMovementType::PURCHASE_IN, $movement->type);
    }

    public function test_movement_quantity_uses_three_decimal_precision(): void
    {
        $movement = StockMovement::factory()->create(['quantity' => '3.750']);

        $this->assertSame('3.750', $movement->refresh()->quantity);
    }

    public function test_movement_quantity_must_be_greater_than_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockMovement::factory()->create(['quantity' => 0]);
    }

    public function test_movement_quantity_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockMovement::factory()->create(['quantity' => -1]);
    }

    public function test_movement_is_append_only_and_cannot_be_updated(): void
    {
        $movement = StockMovement::factory()->create();

        $this->expectException(LogicException::class);

        $movement->update(['note' => 'tampered']);
    }

    public function test_movement_is_append_only_and_cannot_be_deleted(): void
    {
        $movement = StockMovement::factory()->create();

        $this->expectException(LogicException::class);

        $movement->delete();
    }

    public function test_movement_unit_cost_follows_the_money_convention(): void
    {
        $movement = StockMovement::factory()->create(['unit_cost' => 12500]);

        $this->assertSame(12500, $movement->refresh()->unit_cost);
    }

    public function test_movement_relations_resolve_tenant_context(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);

        $movement = StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
        ]);

        $this->assertTrue($movement->store->is($store));
        $this->assertTrue($movement->stockLocation->is($location));
        $this->assertTrue($movement->item->is($item));
        $this->assertSame(1, $store->stockMovements()->count());
    }

    public function test_idempotency_key_is_unique_per_store(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);

        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'idempotency_key' => 'order:1:reserve',
        ]);

        $this->expectException(QueryException::class);

        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'idempotency_key' => 'order:1:reserve',
        ]);
    }

    public function test_same_idempotency_key_is_allowed_across_stores(): void
    {
        [, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        StockMovement::factory()->forStore($storeA)->create([
            'idempotency_key' => 'order:1:reserve',
        ]);
        StockMovement::factory()->forStore($storeB)->create([
            'idempotency_key' => 'order:1:reserve',
        ]);

        $this->assertSame(1, $storeA->stockMovements()->count());
        $this->assertSame(1, $storeB->stockMovements()->count());
    }

    public function test_null_idempotency_keys_do_not_collide(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = Item::factory()->for($store, 'store')->create(['tracks_stock' => true]);

        StockMovement::factory()->count(2)->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'idempotency_key' => null,
        ]);

        $this->assertSame(2, $store->stockMovements()->count());
    }

    public function test_movement_foreign_key_rejects_a_missing_location(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $item = Item::factory()->for($store, 'store')->create();

        $this->expectException(QueryException::class);

        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => 999999,
            'item_id' => $item->id,
        ]);
    }

    public function test_movement_foreign_key_rejects_a_missing_item(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $location = StockLocation::factory()->for($store, 'store')->create();

        $this->expectException(QueryException::class);

        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => 999999,
        ]);
    }

    public function test_movement_foreign_key_rejects_a_missing_store(): void
    {
        $this->expectException(QueryException::class);

        StockMovement::factory()->create(['store_id' => 999999]);
    }

    public function test_movement_order_links_are_nullable(): void
    {
        $movement = StockMovement::factory()->create();

        $this->assertNull($movement->refresh()->order_id);
        $this->assertNull($movement->order_item_id);
        $this->assertNull($movement->reversal_of_id);
    }
}
