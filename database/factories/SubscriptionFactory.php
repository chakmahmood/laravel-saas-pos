<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ];
    }
}
