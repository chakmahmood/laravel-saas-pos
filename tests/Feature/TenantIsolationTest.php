<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_business_endpoint_requires_authentication(): void
    {
        $this->defineBusinessRoute();

        $this->getJson('/api/_test/business')->assertUnauthorized();
    }

    public function test_business_request_is_scoped_to_the_token_current_store(): void
    {
        $this->defineBusinessRoute();

        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($userA, $storeA);
        $tokenB = $this->issueToken($userB, $storeB);

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/_test/business')
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeA->id);

        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/_test/business')
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeB->id);
    }

    public function test_client_supplied_store_id_cannot_override_authorization(): void
    {
        $this->defineBusinessRoute();

        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/_test/business?store_id='.$storeB->id)
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeA->id)
            ->assertJsonPath('data.client_store_id', (string) $storeB->id);

        $this->withHeaders($this->bearer($tokenA))
            ->postJson('/api/_test/business', ['store_id' => $storeB->id])
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeA->id);
    }

    public function test_error_response_does_not_leak_other_tenant_details(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeA);

        $response = $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $storeB->id]);

        $response->assertForbidden();

        $this->assertStringNotContainsString($storeB->name, $response->getContent());
        $this->assertStringNotContainsString($storeB->slug, $response->getContent());
    }
}
