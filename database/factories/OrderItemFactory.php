<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(5000, 100000);
        $quantity = 1;

        return [
            'order_id' => Order::factory(),
            'item_id' => null,
            'item_name' => ucfirst(fake()->words(2, true)),
            'item_sku' => null,
            'item_type' => ItemType::PRODUCT->value,
            'unit' => 'pcs',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_subtotal' => $unitPrice * $quantity,
            'discount_amount' => 0,
            'line_total' => $unitPrice * $quantity,
            'notes' => null,
        ];
    }
}
