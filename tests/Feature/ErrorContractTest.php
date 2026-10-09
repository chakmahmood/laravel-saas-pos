<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The API error body must be stable, machine-readable and free of internal
 * details (Eloquent class names, stack traces), especially for resources that
 * belong to another tenant.
 */
class ErrorContractTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_foreign_tenant_resource_returns_a_generic_404(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $orderB = Order::factory()->for($storeB, 'store')->create();

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderB->id)
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Sumber daya tidak ditemukan.',
                'code' => 'not_found',
            ]);
    }

    public function test_undefined_api_route_returns_a_generic_404(): void
    {
        $this->getJson('/api/this-route-does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Sumber daya tidak ditemukan.',
                'code' => 'not_found',
            ]);
    }

    public function test_policy_denial_returns_a_coded_forbidden(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Baru'])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Akses ditolak.',
                'code' => 'forbidden',
            ]);
    }

    public function test_missing_token_returns_a_coded_unauthenticated(): void
    {
        $this->getJson('/api/categories')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Tidak terautentikasi.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_invalid_token_returns_a_coded_unauthenticated(): void
    {
        $this->getJson('/api/categories', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Tidak terautentikasi.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_validation_error_has_a_stable_code_and_errors(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', []);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'validation_error')
            ->assertJsonValidationErrors('name');
    }

    public function test_rate_limit_error_is_coded_and_does_not_leak_a_trace(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'probe@example.com', 'password' => 'x']);
        }

        $response = $this->postJson('/api/auth/login', ['email' => 'probe@example.com', 'password' => 'x']);

        $response->assertStatus(429)
            ->assertExactJson([
                'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
                'code' => 'too_many_requests',
            ]);
    }
}
