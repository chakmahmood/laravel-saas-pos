<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StoreBusinessTypeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registerPayload(string $slug, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner '.$slug,
            'email' => $slug.'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Store '.$slug,
            'store_slug' => $slug,
        ], $overrides);
    }

    public function test_only_retail_and_service_are_canonical_values(): void
    {
        $this->assertSame(['retail', 'service'], BusinessType::canonicalValues());
        $this->assertTrue(BusinessType::RETAIL->usesInventory());
        $this->assertFalse(BusinessType::SERVICE->usesInventory());
    }

    public function test_store_defaults_to_service_when_type_is_not_provided(): void
    {
        $owner = User::factory()->create();

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Toko Lama',
            'slug' => 'toko-lama',
        ]);

        $store->refresh();

        $this->assertSame(BusinessType::SERVICE, $store->business_type);
    }

    public function test_register_requires_a_business_type(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('required'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');
    }

    public function test_register_accepts_the_retail_group(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('warung', [
            'business_type' => 'retail',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.store.business_type', 'retail');

        $this->assertDatabaseHas('stores', [
            'slug' => 'warung',
            'business_type' => 'retail',
        ]);
    }

    public function test_register_accepts_the_service_group(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('bengkel', [
            'business_type' => 'service',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.store.business_type', 'service');

        $this->assertDatabaseHas('stores', [
            'slug' => 'bengkel',
            'business_type' => 'service',
        ]);
    }

    public function test_register_normalizes_legacy_aliases_to_canonical_groups(): void
    {
        $this->freePlan();

        $cases = [
            'resto' => ['input' => 'restaurant', 'expected' => 'retail'],
            'laundry' => ['input' => 'laundry', 'expected' => 'service'],
            'servis' => ['input' => 'repair', 'expected' => 'service'],
        ];

        foreach ($cases as $slug => $case) {
            $this->postJson('/api/auth/register', $this->registerPayload($slug, [
                'business_type' => $case['input'],
            ]))
                ->assertCreated()
                ->assertJsonPath('data.store.business_type', $case['expected']);

            $this->assertDatabaseHas('stores', [
                'slug' => $slug,
                'business_type' => $case['expected'],
            ]);
        }
    }

    public function test_register_rejects_an_unknown_business_type(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', $this->registerPayload('tono', [
            'business_type' => 'spaceship',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');
    }

    public function test_store_model_canonicalizes_legacy_values_on_read(): void
    {
        $owner = User::factory()->create();

        DB::table('stores')->insert([
            'owner_id' => $owner->id,
            'name' => 'Legacy Laundry',
            'slug' => 'legacy-laundry',
            'is_active' => true,
            'business_type' => 'laundry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = Store::query()->where('slug', 'legacy-laundry')->firstOrFail();

        $this->assertSame(BusinessType::SERVICE, $store->business_type);
    }

    public function test_current_store_endpoint_exposes_canonical_business_type(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store')
            ->assertOk()
            ->assertJsonPath('data.current_store.business_type', 'retail');
    }

    public function test_client_cannot_change_business_type_through_the_current_store_endpoint(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user, ['business_type' => 'service']);
        $this->attachMember($user, $storeB, 'owner', true);

        $token = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', [
                'store_id' => $storeB->id,
                'business_type' => 'retail',
            ])
            ->assertOk();

        // Only the token selection changed; the target store keeps its type.
        $this->assertDatabaseHas('stores', [
            'id' => $storeB->id,
            'business_type' => 'service',
        ]);
    }
}
