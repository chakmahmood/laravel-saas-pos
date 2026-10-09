<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_stores' => 1,
            'max_users_per_store' => 2,
            'max_products' => 100,
            'max_transactions_per_month' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Free',
            'slug' => 'free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
