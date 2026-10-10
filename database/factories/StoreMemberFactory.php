<?php

namespace Database\Factories;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreMember>
 */
class StoreMemberFactory extends Factory
{
    protected $model = StoreMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'role' => StoreRole::CASHIER->value,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => StoreRole::OWNER->value]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => StoreRole::ADMIN->value]);
    }

    public function cashier(): static
    {
        return $this->state(fn (): array => ['role' => StoreRole::CASHIER->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
