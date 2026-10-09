<?php

namespace Database\Factories;

use App\Enums\StockLocationType;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLocation>
 */
class StockLocationFactory extends Factory
{
    protected $model = StockLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Lokasi '.fake()->unique()->numerify('####'),
            'code' => null,
            'type' => StockLocationType::OUTLET->value,
            'is_default' => false,
            'is_active' => true,
            'default_guard' => null,
        ];
    }

    /**
     * Mark the location as the store default and maintain the portable unique
     * guard. Real code sets this through the provisioning/service layer; the
     * factory mirrors it so tests exercise the same invariant.
     */
    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ])->afterCreating(function (StockLocation $location): void {
            $location->forceFill([
                'default_guard' => (string) $location->store_id,
            ])->save();
        });
    }

    public function ofType(StockLocationType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
