<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'order_id' => Order::factory(),
            'payment_method' => PaymentMethod::CASH->value,
            'amount' => fake()->numberBetween(5000, 100000),
            'status' => PaymentRecordStatus::COMPLETED->value,
            'reference_number' => null,
            'notes' => null,
            'paid_at' => now(),
            'recorded_by' => null,
        ];
    }
}
