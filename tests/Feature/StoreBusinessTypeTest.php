<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StoreBusinessTypeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_store_defaults_to_other_when_business_type_is_not_provided(): void
    {
        $owner = User::factory()->create();

        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Toko Lama',
            'slug' => 'toko-lama',
        ]);

        $store->refresh();

        $this->assertSame(BusinessType::OTHER, $store->business_type);
    }

    public function test_register_without_business_type_defaults_to_other(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', [
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Toko Budi',
            'store_slug' => 'toko-budi',
        ])
            ->assertCreated()
            ->assertJsonPath('data.store.business_type', BusinessType::OTHER->value);

        $this->assertDatabaseHas('stores', [
            'slug' => 'toko-budi',
            'business_type' => BusinessType::OTHER->value,
        ]);
    }

    public function test_register_accepts_a_supported_business_type(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', [
            'name' => 'Sari',
            'email' => 'sari@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Warung Sari',
            'store_slug' => 'warung-sari',
            'business_type' => BusinessType::RESTAURANT->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.store.business_type', BusinessType::RESTAURANT->value);

        $this->assertDatabaseHas('stores', [
            'slug' => 'warung-sari',
            'business_type' => BusinessType::RESTAURANT->value,
        ]);
    }

    public function test_register_rejects_an_unknown_business_type(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', [
            'name' => 'Tono',
            'email' => 'tono@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Toko Tono',
            'store_slug' => 'toko-tono',
            'business_type' => 'spaceship',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');
    }

    public function test_current_store_endpoint_exposes_business_type(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store')
            ->assertOk()
            ->assertJsonPath(
                'data.current_store.business_type',
                $store->business_type->value,
            );
    }
}
