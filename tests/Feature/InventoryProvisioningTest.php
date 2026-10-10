<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class InventoryProvisioningTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function registerPayload(string $slug, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner '.$slug,
            'email' => $slug.'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Store '.$slug,
            'store_slug' => $slug,
        ], $overrides);
    }

    public function test_registering_an_inventory_store_creates_a_default_location(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('retail', [
            'business_type' => 'retail',
        ]))->assertCreated();

        $store = Store::query()->where('slug', 'retail')->firstOrFail();

        $this->assertSame(1, $store->stockLocations()->where('is_default', true)->count());

        $location = $store->stockLocations()->where('is_default', true)->firstOrFail();
        $this->assertTrue($location->is_active);
        $this->assertSame((string) $store->id, $location->default_guard);
    }

    public function test_registering_a_service_store_does_not_create_a_location(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('service', [
            'business_type' => 'service',
        ]))->assertCreated();

        $store = Store::query()->where('slug', 'service')->firstOrFail();

        $this->assertSame(0, $store->stockLocations()->count());
    }

    public function test_registering_without_business_type_is_rejected(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('plain'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');
    }

    public function test_provision_command_only_processes_inventory_stores(): void
    {
        $retail = $this->createStore(User::factory()->create(), ['business_type' => 'retail']);
        $restaurant = $this->createStore(User::factory()->create(), ['business_type' => 'restaurant']);
        $laundry = $this->createStore(User::factory()->create(), ['business_type' => 'laundry']);

        $this->artisan('stock:provision-locations')->assertSuccessful();

        $this->assertSame(1, $retail->stockLocations()->where('is_default', true)->count());
        $this->assertSame(1, $restaurant->stockLocations()->where('is_default', true)->count());
        $this->assertSame(0, $laundry->stockLocations()->count());
    }

    public function test_provision_command_is_idempotent(): void
    {
        $retail = $this->createStore(User::factory()->create(), ['business_type' => 'retail']);

        $this->artisan('stock:provision-locations')->assertSuccessful();
        $this->artisan('stock:provision-locations')->assertSuccessful();

        $this->assertSame(1, $retail->stockLocations()->where('is_default', true)->count());
    }

    public function test_provision_command_processes_legacy_inventory_aliases(): void
    {
        // Raw insert bypasses the model cast, emulating un-normalized legacy data.
        $owner = User::factory()->create();

        DB::table('stores')->insert([
            'owner_id' => $owner->id,
            'name' => 'Legacy Resto',
            'slug' => 'legacy-resto',
            'is_active' => true,
            'business_type' => 'restaurant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('stock:provision-locations')->assertSuccessful();

        $store = Store::query()->where('slug', 'legacy-resto')->firstOrFail();

        $this->assertSame(1, $store->stockLocations()->where('is_default', true)->count());
        $this->assertSame(BusinessType::RETAIL, $store->business_type);
    }
}
