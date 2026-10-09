<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CurrentStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_show_returns_current_store_details(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store')
            ->assertOk()
            ->assertJsonPath('data.current_store.id', $store->id)
            ->assertJsonPath('data.current_store.name', $store->name)
            ->assertJsonPath('data.current_store.slug', $store->slug)
            ->assertJsonPath('data.current_store.role', StoreRole::OWNER->value)
            ->assertJsonPath('data.current_store.is_active', true);
    }

    public function test_show_returns_null_when_token_has_no_current_store(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store')
            ->assertOk()
            ->assertJsonPath('data.current_store', null);
    }

    public function test_user_can_switch_current_store(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user);
        $this->attachMember($user, $storeB, StoreRole::ADMIN->value, true);

        $token = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $storeB->id])
            ->assertOk()
            ->assertJsonPath('data.current_store.id', $storeB->id)
            ->assertJsonPath('data.current_store.role', StoreRole::ADMIN->value);

        $this->assertSame($storeB->id, $this->currentStoreIdOnToken($token));

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store')
            ->assertOk()
            ->assertJsonPath('data.current_store.id', $storeB->id);
    }

    public function test_switching_one_token_does_not_affect_another_token(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user);
        $this->attachMember($user, $storeB);

        $tokenOne = $this->issueToken($user, $storeA);
        $tokenTwo = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($tokenOne))
            ->putJson('/api/current-store', ['store_id' => $storeB->id])
            ->assertOk();

        $this->assertSame($storeB->id, $this->currentStoreIdOnToken($tokenOne));
        $this->assertSame($storeA->id, $this->currentStoreIdOnToken($tokenTwo));
    }

    public function test_user_cannot_select_store_without_membership(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $storeB->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'store_not_accessible');

        $this->assertSame($storeA->id, $this->currentStoreIdOnToken($token));
    }

    public function test_user_cannot_select_store_with_inactive_membership(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user);
        $this->attachMember($user, $storeB, StoreRole::OWNER->value, false);

        $token = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $storeB->id])
            ->assertForbidden();

        $this->assertSame($storeA->id, $this->currentStoreIdOnToken($token));
    }

    public function test_user_cannot_select_inactive_store(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user, ['is_active' => false]);
        $this->attachMember($user, $storeB);

        $token = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', ['store_id' => $storeB->id])
            ->assertForbidden();

        $this->assertSame($storeA->id, $this->currentStoreIdOnToken($token));
    }

    public function test_update_requires_store_id(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/current-store', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');
    }

    public function test_business_endpoint_requires_current_store_selection(): void
    {
        $this->defineBusinessRoute();

        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/_test/business')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_business_endpoint_rejects_stale_current_store_and_clears_it(): void
    {
        $this->defineBusinessRoute();

        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $user->stores()->updateExistingPivot($store->id, ['is_active' => false]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/_test/business')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');

        $this->assertNull($this->currentStoreIdOnToken($token));
    }

    public function test_business_endpoint_rejects_deactivated_store(): void
    {
        $this->defineBusinessRoute();

        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $store->update(['is_active' => false]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/_test/business')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');
    }
}
