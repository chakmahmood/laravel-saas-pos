<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'stock_location_id' => StockLocation::factory(),
            'item_id' => Item::factory(),
            'type' => StockMovementType::OPENING->value,
            'quantity' => 1,
            'unit_cost' => null,
            'order_id' => null,
            'order_item_id' => null,
            'reversal_of_id' => null,
            'idempotency_key' => null,
            'note' => null,
            'created_by' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * Build a movement whose store, location and item are all in the same
     * tenant.
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

    public function ofType(StockMovementType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type->value,
        ]);
    }
}
