<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Paket gratis untuk memulai bisnis.',
                'price_monthly' => 0,
                'price_yearly' => 0,

                'max_stores' => 1,
                'max_users_per_store' => 2,
                'max_products' => 100,
                'max_transactions_per_month' => 500,

                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        Plan::updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Paket untuk bisnis yang sedang berkembang.',
                'price_monthly' => 99000,
                'price_yearly' => 990000,

                'max_stores' => 5,
                'max_users_per_store' => 10,
                'max_products' => 5000,
                'max_transactions_per_month' => 10000,

                'is_active' => true,
                'sort_order' => 2,
            ]
        );
    }
}
