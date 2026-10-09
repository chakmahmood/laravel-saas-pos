<?php

namespace Tests\Feature;

use App\Enums\StockLocationType;
use App\Models\StockLocation;
use App\Services\StockLocationProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockLocationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_location_belongs_to_its_store(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $location = StockLocation::factory()->for($store, 'store')->create();

        $this->assertTrue($location->store->is($store));
        $this->assertTrue($store->stockLocations()->whereKey($location->id)->exists());
    }

    public function test_location_type_is_cast_to_enum(): void
    {
        $location = StockLocation::factory()
            ->ofType(StockLocationType::WAREHOUSE)
            ->create();

        $this->assertSame(StockLocationType::WAREHOUSE, $location->type);
    }

    public function test_location_name_is_unique_per_store(): void
    {
        [, $store] = $this->createOwnerWithStore();

        StockLocation::factory()->for($store, 'store')->create(['name' => 'Gudang']);

        $this->expectException(QueryException::class);

        StockLocation::factory()->for($store, 'store')->create(['name' => 'Gudang']);
    }

    public function test_location_name_may_be_reused_by_another_store(): void
    {
        [, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        StockLocation::factory()->for($storeA, 'store')->create(['name' => 'Gudang']);
        StockLocation::factory()->for($storeB, 'store')->create(['name' => 'Gudang']);

        $this->assertSame(1, $storeA->stockLocations()->count());
        $this->assertSame(1, $storeB->stockLocations()->count());
    }

    public function test_multiple_non_default_locations_are_allowed(): void
    {
        [, $store] = $this->createOwnerWithStore();

        StockLocation::factory()->for($store, 'store')->create();
        StockLocation::factory()->for($store, 'store')->create();

        $this->assertSame(2, $store->stockLocations()->count());
        $this->assertSame(0, $store->stockLocations()->where('is_default', true)->count());
    }

    public function test_default_guard_prevents_a_second_default_per_store(): void
    {
        [, $store] = $this->createOwnerWithStore();

        StockLocation::factory()->for($store, 'store')->default()->create();

        $this->expectException(QueryException::class);

        StockLocation::factory()->for($store, 'store')->default()->create();
    }

    public function test_default_guard_is_scoped_per_store(): void
    {
        [, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $a = StockLocation::factory()->for($storeA, 'store')->default()->create();
        $b = StockLocation::factory()->for($storeB, 'store')->default()->create();

        $this->assertSame((string) $storeA->id, $a->default_guard);
        $this->assertSame((string) $storeB->id, $b->default_guard);
    }

    public function test_provisioner_creates_a_default_location(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $location = app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

        $this->assertTrue($location->is_default);
        $this->assertTrue($location->is_active);
        $this->assertSame((string) $store->id, $location->default_guard);
        $this->assertTrue($location->store->is($store));
        $this->assertSame(StockLocationType::OUTLET, $location->type);
    }

    public function test_provisioner_is_idempotent(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $provisioner = app(StockLocationProvisioner::class);

        $first = $provisioner->ensureDefaultForStore($store);
        $second = $provisioner->ensureDefaultForStore($store);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $store->stockLocations()->count());
        $this->assertSame(1, $store->stockLocations()->where('is_default', true)->count());
    }

    public function test_provisioner_backfills_only_stores_without_a_default(): void
    {
        [, $withDefault] = $this->createOwnerWithStore();
        [, $missingA] = $this->createOwnerWithStore();
        [, $missingB] = $this->createOwnerWithStore();

        StockLocation::factory()->for($withDefault, 'store')->default()->create();

        $provisioned = app(StockLocationProvisioner::class)->provisionMissingLocations();

        $this->assertSame(2, $provisioned);
        $this->assertSame(1, $withDefault->stockLocations()->where('is_default', true)->count());
        $this->assertSame(1, $missingA->stockLocations()->where('is_default', true)->count());
        $this->assertSame(1, $missingB->stockLocations()->where('is_default', true)->count());
    }

    public function test_provision_command_is_idempotent(): void
    {
        [, $store] = $this->createOwnerWithStore();

        $this->artisan('stock:provision-locations')->assertSuccessful();
        $this->artisan('stock:provision-locations')->assertSuccessful();

        $this->assertSame(1, $store->stockLocations()->where('is_default', true)->count());
    }
}
