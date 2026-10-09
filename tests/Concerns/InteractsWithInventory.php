<?php

namespace Tests\Concerns;

use App\Enums\BusinessType;
use App\Enums\ItemType;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Services\StockLocationProvisioner;

/**
 * Helpers for inventory feature tests.
 *
 * Must be used together with InteractsWithTenants (for createOwnerWithStore,
 * createStore, attachMember, issueToken, bearer).
 */
trait InteractsWithInventory
{
    /**
     * @return array{0: User, 1: Store}
     */
    protected function inventoryStore(): array
    {
        [$owner, $store] = $this->createOwnerWithStore();

        app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

        return [$owner, $store->refresh()];
    }

    /**
     * @return array{0: User, 1: Store}
     */
    protected function nonInventoryStore(): array
    {
        $owner = User::factory()->create();
        $store = $this->createStore($owner, ['business_type' => BusinessType::LAUNDRY->value]);
        $this->attachMember($owner, $store, StoreRole::OWNER->value, true);

        return [$owner, $store];
    }

    protected function defaultLocation(Store $store): StockLocation
    {
        return $store->stockLocations()->where('is_default', true)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function trackedItem(Store $store, array $overrides = []): Item
    {
        return Item::factory()->for($store, 'store')->create(array_merge([
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 10000,
            'tracks_stock' => true,
            'is_active' => true,
        ], $overrides));
    }

    protected function setStock(
        Store $store,
        Item $item,
        string $onHand,
        string $reserved = '0',
        ?StockLocation $location = null,
    ): StockBalance {
        return StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => ($location ?? $this->defaultLocation($store))->id,
            'item_id' => $item->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
        ]);
    }
}
