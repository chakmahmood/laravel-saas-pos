<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_register_creates_user_store_membership_free_plan_and_subscription(): void
    {
        $this->freePlan();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Toko Budi',
            'store_slug' => 'toko-budi',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'budi@example.com')
            ->assertJsonPath('data.store.slug', 'toko-budi')
            ->assertJsonPath('data.subscription.plan.slug', 'free')
            ->assertJsonStructure(['data' => ['token', 'token_type']]);

        $user = User::query()->where('email', 'budi@example.com')->firstOrFail();
        $store = Store::query()->where('slug', 'toko-budi')->firstOrFail();

        $this->assertDatabaseHas('store_user', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role' => StoreRole::OWNER->value,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'store_id' => $store->id,
            'status' => 'active',
        ]);

        $accessToken = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($accessToken);
        $this->assertSame($store->id, (int) $accessToken->current_store_id);
    }

    public function test_login_returns_token_and_selects_first_accessible_store(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type']])
            ->assertJsonPath('data.user.id', $user->id);

        $accessToken = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($accessToken);
        $this->assertSame($store->id, (int) $accessToken->current_store_id);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        [$user] = $this->createOwnerWithStore();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $tokenOne = $this->issueToken($user, $store);
        $tokenTwo = $this->issueToken($user, $store);
        $tokenOneId = PersonalAccessToken::findToken($tokenOne)->id;

        $this->withHeaders($this->bearer($tokenOne))
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenOneId,
        ]);

        $this->withHeaders($this->bearer($tokenTwo))
            ->getJson('/api/me')
            ->assertOk();
    }

    public function test_me_returns_user_and_accessible_stores(): void
    {
        [$user, $storeA] = $this->createOwnerWithStore();
        $storeB = $this->createStore($user);
        $this->attachMember($user, $storeB, StoreRole::ADMIN->value, true);

        $token = $this->issueToken($user, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonCount(2, 'data.stores');
    }

    public function test_protected_endpoints_reject_request_without_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/current-store')->assertUnauthorized();
    }

    public function test_revoked_token_cannot_be_used(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        PersonalAccessToken::findToken($token)->delete();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/me')
            ->assertUnauthorized();
    }
}
