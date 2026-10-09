<?php

namespace Database\Factories;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'cashier_id' => User::factory(),
            'status' => CashSessionStatus::OPEN->value,
            'opening_cash' => 0,
            'opened_at' => now(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => CashSessionStatus::CLOSED->value,
            'closed_at' => now(),
        ]);
    }
}
