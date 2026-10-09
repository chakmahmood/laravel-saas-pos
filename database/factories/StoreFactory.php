<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->company(),
            'slug' => 'store-'.Str::lower(Str::random(12)),
            'is_active' => true,
            'business_type' => BusinessType::RETAIL->value,
        ];
    }

    public function ofType(BusinessType $type): static
    {
        return $this->state(fn (): array => [
            'business_type' => $type->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
