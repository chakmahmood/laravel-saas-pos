<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'cash_session_id' => CashSession::factory(),
            'user_id' => User::factory(),
            'type' => CashMovementType::CASH_IN->value,
            'amount' => 10000,
            'reason' => 'Pergerakan kas uji',
        ];
    }
}
