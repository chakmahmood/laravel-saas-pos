<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\StoreRole;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const PASSWORD = 'CobaPOS123!';

    private const ACCOUNTS = [
        'owner.retail@example.com' => [
            'name' => 'Demo Owner Retail',
            'store_name' => 'Demo Toko Retail',
            'store_slug' => 'demo-toko-retail',
            'owner_email' => 'owner.retail@example.com',
            'business_type' => 'retail',
            'role' => 'owner',
        ],
        'owner.service@example.com' => [
            'name' => 'Demo Owner Service',
            'store_name' => 'Demo Jasa Service',
            'store_slug' => 'demo-jasa-service',
            'owner_email' => 'owner.service@example.com',
            'business_type' => 'service',
            'role' => 'owner',
        ],
        'admin.retail@example.com' => [
            'name' => 'Demo Admin Retail',
            'store_name' => 'Demo Toko Retail',
            'store_slug' => 'demo-toko-retail',
            'owner_email' => 'owner.retail@example.com',
            'business_type' => 'retail',
            'role' => 'admin',
        ],
        'kasir.retail@example.com' => [
            'name' => 'Demo Kasir Retail',
            'store_name' => 'Demo Toko Retail',
            'store_slug' => 'demo-toko-retail',
            'owner_email' => 'owner.retail@example.com',
            'business_type' => 'retail',
            'role' => 'cashier',
        ],
        'kasir.service@example.com' => [
            'name' => 'Demo Kasir Service',
            'store_name' => 'Demo Jasa Service',
            'store_slug' => 'demo-jasa-service',
            'owner_email' => 'owner.service@example.com',
            'business_type' => 'service',
            'role' => 'cashier',
        ],
    ];

    public function test_database_seeder_provisions_demo_accounts_and_all_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('stores', 2);
        $this->assertDatabaseCount('store_user', 5);
        $this->assertDatabaseCount('subscriptions', 2);

        $freePlan = Plan::query()->where('slug', 'free')->firstOrFail();

        foreach (self::ACCOUNTS as $email => $account) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $membership = $user->memberships()->with('store')->firstOrFail();

            $this->assertSame($account['name'], $user->name);
            $this->assertSame($account['store_name'], $membership->store->name);
            $this->assertSame($account['store_slug'], $membership->store->slug);
            $this->assertSame(
                User::query()->where('email', $account['owner_email'])->value('id'),
                $membership->store->owner_id,
            );
            $this->assertSame(BusinessType::from($account['business_type']), $membership->store->business_type);
            $this->assertSame(StoreRole::from($account['role']), $membership->role);
            $this->assertTrue($membership->is_active);
            $this->assertFalse($user->must_change_password);
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

            $this->assertDatabaseHas('subscriptions', [
                'store_id' => $membership->store->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'ends_at' => null,
            ]);

            $response = $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => self::PASSWORD,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.user.email', $email);

            $accessToken = PersonalAccessToken::findToken($response->json('data.token'));

            $this->assertNotNull($accessToken);
            $this->assertSame($membership->store->id, (int) $accessToken->current_store_id);
        }

        $this->assertDatabaseHas('plans', [
            'id' => $freePlan->id,
            'is_active' => true,
            'price_monthly' => 0,
        ]);
    }

    public function test_running_database_seeder_repeatedly_does_not_duplicate_demo_records(): void
    {
        $this->seed(DatabaseSeeder::class);
        $originalUserIds = User::query()
            ->whereIn('email', array_keys(self::ACCOUNTS))
            ->orderBy('email')
            ->pluck('id', 'email')
            ->all();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('stores', 2);
        $this->assertDatabaseCount('store_user', 5);
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertSame(
            $originalUserIds,
            User::query()
                ->whereIn('email', array_keys(self::ACCOUNTS))
                ->orderBy('email')
                ->pluck('id', 'email')
                ->all(),
        );
    }

    public function test_demo_cashier_cannot_select_or_access_another_store(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->defineBusinessRoute();

        $retailStore = Store::query()->where('slug', 'demo-toko-retail')->firstOrFail();
        $serviceStore = Store::query()->where('slug', 'demo-jasa-service')->firstOrFail();
        $response = $this->postJson('/api/auth/login', [
            'email' => 'kasir.retail@example.com',
            'password' => self::PASSWORD,
        ])->assertOk();
        $token = $response->json('data.token');

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $serviceStore->id])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/_test/business')
            ->assertOk()
            ->assertJsonPath('data.store_id', $retailStore->id);
    }

    public function test_demo_accounts_are_not_seeded_outside_local_or_testing(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            (new DemoAccountSeeder)->run();
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('store_user', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
