<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\Item;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'category_id' => null,
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'type' => ItemType::PRODUCT->value,
            'sku' => null,
            'barcode' => null,
            'description' => fake()->boolean(50) ? fake()->sentence() : null,
            'cost_price' => fake()->boolean(50) ? fake()->numberBetween(1000, 50000) : null,
            'selling_price' => fake()->numberBetween(1000, 100000),
            'unit' => 'pcs',
            'is_active' => true,
        ];
    }

    public function ofType(ItemType $type): static
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
