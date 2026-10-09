<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    protected $model = StockBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'stock_location_id' => StockLocation::factory(),
            'item_id' => Item::factory(),
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
        ];
    }

    /**
     * Build a balance whose store, location and item all belong to the same
     * tenant. Foreign keys alone do not guarantee that, so tests should use
     * this helper whenever tenant consistency matters.
     */
    public function forStore(Store $store): static
    {
        return $this->state(fn (): array => [
            'store_id' => $store->getKey(),
            'stock_location_id' => fn () => StockLocation::factory()
                ->for($store, 'store')
                ->create()
                ->getKey(),
            'item_id' => fn () => Item::factory()
                ->for($store, 'store')
                ->create()
                ->getKey(),
        ]);
    }
}
