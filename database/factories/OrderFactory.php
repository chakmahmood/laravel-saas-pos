<?php

namespace Database\Factories;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = fake()->numberBetween(10000, 500000);

        return [
            'store_id' => Store::factory(),
            'order_number' => 'TRX-'.fake()->unique()->numerify('########-####'),
            'customer_id' => null,
            'cashier_id' => null,
            'subtotal' => $total,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::UNPAID->value,
            'fulfillment_status' => FulfillmentStatus::PENDING->value,
            'notes' => null,
            'placed_at' => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'paid_amount' => $attributes['total_amount'],
            'payment_status' => PaymentStatus::PAID->value,
        ]);
    }
}
