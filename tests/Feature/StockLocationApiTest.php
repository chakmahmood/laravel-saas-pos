<?php

namespace Tests\Feature;

use App\Enums\StockLocationType;
use App\Enums\StoreRole;
use App\Models\Order;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockLocationApiTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    public function test_stock_location_endpoints_require_authentication(): void
    {
        $this->getJson('/api/stock/locations')->assertUnauthorized();
        $this->postJson('/api/stock/locations', ['name' => 'X', 'type' => 'outlet'])->assertUnauthorized();
        $this->getJson('/api/stock/locations/1')->assertUnauthorized();
        $this->patchJson('/api/stock/locations/1', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/stock/locations/1')->assertUnauthorized();
    }

    public function test_token_without_current_store_is_rejected(): void
    {
        [$user] = $this->inventoryStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_non_inventory_store_gets_inventory_not_available(): void
    {
        [$owner, $store] = $this->nonInventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations')
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', ['name' => 'Gudang', 'type' => 'warehouse'])
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/balances')
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements')
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$item->id.'/stock')
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');
    }

    public function test_owner_can_manage_a_stock_location(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $token = $this->issueToken($owner, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', [
                'name' => 'Gudang Belakang',
                'code' => 'GDG',
                'type' => StockLocationType::WAREHOUSE->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gudang Belakang')
            ->assertJsonPath('data.code', 'GDG')
            ->assertJsonPath('data.type', StockLocationType::WAREHOUSE->value)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonStructure(['message', 'data' => ['id', 'name', 'code', 'type', 'is_default', 'is_active', 'created_at', 'updated_at']]);

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/stock/locations/'.$id, ['name' => 'Gudang Utama Belakang'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gudang Utama Belakang');

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$id)
            ->assertOk();

        $this->assertDatabaseMissing('stock_locations', ['id' => $id]);
    }

    public function test_cashier_can_read_but_cannot_manage_locations(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $location = $this->defaultLocation($store);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations/'.$location->id)
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', ['name' => 'Baru', 'type' => 'outlet'])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/stock/locations/'.$location->id, ['name' => 'Diubah'])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$location->id)
            ->assertForbidden();
    }

    public function test_index_only_returns_locations_of_the_current_store(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        [, $storeB] = $this->inventoryStore();

        StockLocation::factory()->for($storeB, 'store')->create(['name' => 'Milik B']);

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_default', true);
    }

    public function test_other_tenant_location_is_not_accessible(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        [, $storeB] = $this->inventoryStore();
        $locationB = StockLocation::factory()->for($storeB, 'store')->create();

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/locations/'.$locationB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/stock/locations/'.$locationB->id, ['name' => 'X'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$locationB->id)
            ->assertNotFound();

        $this->assertDatabaseHas('stock_locations', ['id' => $locationB->id]);
    }

    public function test_client_cannot_forge_store_id_or_default_flags(): void
    {
        [$owner, $store] = $this->inventoryStore();
        [, $other] = $this->inventoryStore();

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', [
                'name' => 'Coba',
                'type' => 'outlet',
                'store_id' => $other->id,
                'is_default' => true,
                'default_guard' => 'hacked',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('stock_locations', [
            'name' => 'Coba',
            'store_id' => $store->id,
            'is_default' => false,
            'default_guard' => null,
        ]);
    }

    public function test_default_location_cannot_be_deleted(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $location = $this->defaultLocation($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'default_stock_location_protected');

        $this->assertDatabaseHas('stock_locations', ['id' => $location->id]);
    }

    public function test_location_with_balance_cannot_be_deleted(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $location = StockLocation::factory()->for($store, 'store')->create();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '5.000', '0', $location);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'stock_location_in_use');

        $this->assertDatabaseHas('stock_locations', ['id' => $location->id]);
    }

    public function test_location_referenced_by_an_order_cannot_be_deleted(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $location = StockLocation::factory()->for($store, 'store')->create();

        Order::factory()->for($store, 'store')->create([
            'stock_location_id' => $location->id,
        ]);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/locations/'.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'stock_location_in_use');
    }

    public function test_validation_rejects_duplicate_name_and_invalid_type(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $location = $this->defaultLocation($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', [
                'name' => $location->name,
                'type' => 'outlet',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/locations', [
                'name' => 'Valid',
                'type' => 'planet',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }
}
