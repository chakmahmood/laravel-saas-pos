<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The universal catalog is shared by both business groups:
 * - retail focuses on product/menu items,
 * - service focuses on service/package items but may also hold optional
 *   product items.
 *
 * A service store must never be forced to create a product before selling a
 * service, and item type is independent from the store's business type.
 */
class BusinessCatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @return array{0: User, 1: Store}
     */
    private function serviceStore(): array
    {
        return $this->createOwnerWithStore(businessType: 'service');
    }

    public function test_retail_store_can_create_a_product_item(): void
    {
        [$owner, $store] = $this->createOwnerWithStore(businessType: 'retail');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Kopi Susu',
                'type' => 'product',
                'selling_price' => 15000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'product')
            ->assertJsonPath('data.selling_price', 15000);
    }

    public function test_service_store_can_create_a_service_item_without_any_product(): void
    {
        [$owner, $store] = $this->serviceStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Cuci Kering',
                'type' => 'service',
                'selling_price' => 7000,
                'unit' => 'kg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'service')
            ->assertJsonPath('data.selling_price', 7000);
    }

    public function test_service_store_can_also_hold_optional_product_items(): void
    {
        [$owner, $store] = $this->serviceStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Oli Mesin',
                'type' => 'product',
                'selling_price' => 65000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'product');
    }

    public function test_item_type_must_be_a_known_value(): void
    {
        [$owner, $store] = $this->serviceStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Aneh',
                'type' => 'spaceship',
                'selling_price' => 1000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_service_store_cannot_track_stock(): void
    {
        [$owner, $store] = $this->serviceStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Produk Berstok',
                'type' => 'product',
                'selling_price' => 1000,
                'tracks_stock' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');
    }

    public function test_retail_store_can_track_stock(): void
    {
        [$owner, $store] = $this->createOwnerWithStore(businessType: 'retail');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', [
                'name' => 'Produk Berstok',
                'type' => 'product',
                'selling_price' => 1000,
                'tracks_stock' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tracks_stock', true);
    }

    public function test_catalog_is_tenant_isolated_across_business_groups(): void
    {
        [$ownerA, $storeA] = $this->serviceStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore(businessType: 'retail');

        $this->withHeaders($this->bearer($this->issueToken($ownerB, $storeB)))
            ->postJson('/api/items', [
                'name' => 'Produk Toko B',
                'type' => 'product',
                'selling_price' => 2000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($this->issueToken($ownerA, $storeA)))
            ->getJson('/api/items')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
