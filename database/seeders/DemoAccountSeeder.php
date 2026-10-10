<?php

namespace Database\Seeders;

use App\Enums\BusinessType;
use App\Enums\StoreRole;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoAccountSeeder extends Seeder
{
    private const PASSWORD = 'CobaPOS123!';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function (): void {
            $freePlan = Plan::query()
                ->where('slug', 'free')
                ->where('is_active', true)
                ->firstOrFail();

            $ownerRetail = User::updateOrCreate(
                ['email' => 'owner.retail@example.com'],
                [
                    'name' => 'Demo Owner Retail',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $ownerService = User::updateOrCreate(
                ['email' => 'owner.service@example.com'],
                [
                    'name' => 'Demo Owner Service',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $adminRetail = User::updateOrCreate(
                ['email' => 'admin.retail@example.com'],
                [
                    'name' => 'Demo Admin Retail',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $cashierRetail = User::updateOrCreate(
                ['email' => 'kasir.retail@example.com'],
                [
                    'name' => 'Demo Kasir Retail',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $cashierService = User::updateOrCreate(
                ['email' => 'kasir.service@example.com'],
                [
                    'name' => 'Demo Kasir Service',
                    'password' => self::PASSWORD,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $retailStore = Store::updateOrCreate(
                ['slug' => 'demo-toko-retail'],
                [
                    'owner_id' => $ownerRetail->id,
                    'name' => 'Demo Toko Retail',
                    'business_type' => BusinessType::RETAIL,
                    'is_active' => true,
                ],
            );

            $serviceStore = Store::updateOrCreate(
                ['slug' => 'demo-jasa-service'],
                [
                    'owner_id' => $ownerService->id,
                    'name' => 'Demo Jasa Service',
                    'business_type' => BusinessType::SERVICE,
                    'is_active' => true,
                ],
            );

            foreach ([
                [$ownerRetail, $retailStore, StoreRole::OWNER],
                [$adminRetail, $retailStore, StoreRole::ADMIN],
                [$cashierRetail, $retailStore, StoreRole::CASHIER],
                [$ownerService, $serviceStore, StoreRole::OWNER],
                [$cashierService, $serviceStore, StoreRole::CASHIER],
            ] as [$user, $store, $role]) {
                StoreMember::updateOrCreate(
                    [
                        'store_id' => $store->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => $role->value,
                        'is_active' => true,
                    ],
                );
            }

            foreach ([$retailStore, $serviceStore] as $store) {
                Subscription::updateOrCreate(
                    ['store_id' => $store->id],
                    [
                        'plan_id' => $freePlan->id,
                        'status' => 'active',
                        'billing_cycle' => 'monthly',
                        'starts_at' => now(),
                        'ends_at' => null,
                        'trial_ends_at' => null,
                        'cancelled_at' => null,
                    ],
                );
            }
        });
    }
}
