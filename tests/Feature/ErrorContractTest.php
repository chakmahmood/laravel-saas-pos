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
}
