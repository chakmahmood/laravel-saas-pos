<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function addMember(Store $store, string $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $this->attachMember($user, $store, $role, $active);

        return $user;
    }

    private function membershipOf(Store $store, User $user): StoreMember
    {
        return $store->members()->where('user_id', $user->id)->firstOrFail();
    }

    // ---------------------------------------------------------------------
    // Authentication & role gating
    // ---------------------------------------------------------------------

    public function test_member_endpoints_require_authentication(): void
    {
        $this->getJson('/api/current-store/members')->assertUnauthorized();
        $this->postJson('/api/current-store/members/admin', [])->assertUnauthorized();
        $this->postJson('/api/current-store/members/cashiers', [])->assertUnauthorized();
    }

    public function test_cashier_cannot_access_member_management(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $cashier = $this->addMember($store, StoreRole::CASHIER->value);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store/members')
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'X',
                'email' => 'x@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_use_admin_and_cashier_functions_without_a_second_account(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        // Admin function (manage catalog).
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Makanan'])
            ->assertCreated();

        // Cashier function (open a shift).
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/open', ['opening_cash' => 0])
            ->assertCreated();

        // The owner keeps the owner role.
        $this->assertDatabaseHas('store_user', [
            'store_id' => $store->id,
            'user_id' => $owner->id,
            'role' => StoreRole::OWNER->value,
        ]);
        $this->assertSame(1, $store->members()->count());
    }

    // ---------------------------------------------------------------------
    // Direct admin creation + one-active-admin limit
    // ---------------------------------------------------------------------

    public function test_owner_can_create_the_single_admin_account(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/admin', [
                'name' => 'Admin Satu',
                'email' => 'admin@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', StoreRole::ADMIN->value)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email', 'admin@example.com');

        $this->assertStringNotContainsString('password123', $response->getContent());

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check('password123', $admin->password));
        $this->assertDatabaseHas('store_user', [
            'store_id' => $store->id,
            'user_id' => $admin->id,
            'role' => StoreRole::ADMIN->value,
            'is_active' => true,
        ]);
    }

    public function test_owner_cannot_create_a_second_active_admin(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/admin', [
                'name' => 'Admin Satu',
                'email' => 'admin1@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/admin', [
                'name' => 'Admin Dua',
                'email' => 'admin2@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'admin_limit_reached');

        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
    }

    public function test_admin_cannot_create_admin(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $admin = $this->addMember($store, StoreRole::ADMIN->value);
        $token = $this->issueToken($admin, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/admin', [
                'name' => 'Admin Dua',
                'email' => 'admin2@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertForbidden();
    }

    public function test_deactivating_the_admin_frees_the_slot_but_reactivation_checks_the_limit(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $admin1 = $this->addMember($store, StoreRole::ADMIN->value);
        $membership1 = $this->membershipOf($store, $admin1);
        $token = $this->issueToken($owner, $store);

        // Deactivate the current admin -> the slot is free.
        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$membership1->id.'/status', ['is_active' => false])
            ->assertOk();

        // A new admin can now be created directly.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/admin', [
                'name' => 'Admin Baru',
                'email' => 'admin2@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated();

        // Reactivating the old admin is now a conflict.
        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$membership1->id.'/status', ['is_active' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'admin_limit_reached');
    }

    public function test_promoting_a_cashier_to_admin_obeys_the_limit(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $cashierA = $this->addMember($store, StoreRole::CASHIER->value);
        $cashierB = $this->addMember($store, StoreRole::CASHIER->value);
        $a = $this->membershipOf($store, $cashierA);
        $b = $this->membershipOf($store, $cashierB);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$a->id.'/role', ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', StoreRole::ADMIN->value);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$b->id.'/role', ['role' => 'admin'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'admin_limit_reached');
    }

    public function test_role_owner_cannot_be_assigned_and_owner_is_protected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $ownerMembership = $this->membershipOf($store, $owner);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$ownerMembership->id.'/role', ['role' => 'owner'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$ownerMembership->id.'/role', ['role' => 'admin'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'owner_protected');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$ownerMembership->id.'/status', ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'owner_protected');

        $this->assertDatabaseHas('store_user', [
            'id' => $ownerMembership->id,
            'role' => StoreRole::OWNER->value,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // Cashier creation
    // ---------------------------------------------------------------------

    public function test_owner_and_admin_can_create_cashiers(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $admin = $this->addMember($store, StoreRole::ADMIN->value);

        $ownerToken = $this->issueToken($owner, $store);
        $adminToken = $this->issueToken($admin, $store);

        $this->withHeaders($this->bearer($ownerToken))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Kasir Owner',
                'email' => 'kasir1@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', StoreRole::CASHIER->value);

        $this->withHeaders($this->bearer($adminToken))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Kasir Admin',
                'email' => 'kasir2@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('store_user', [
            'store_id' => $store->id,
            'role' => StoreRole::CASHIER->value,
        ]);
    }

    public function test_cashier_cannot_create_cashier(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $cashier = $this->addMember($store, StoreRole::CASHIER->value);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Kasir Baru',
                'email' => 'kasir3@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertForbidden();
    }

    public function test_duplicate_email_is_rejected_without_creating_an_account(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $before = User::query()->count();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Duplikat',
                'email' => $owner->email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'email_already_registered');

        $this->assertSame($before, User::query()->count());
    }

    // ---------------------------------------------------------------------
    // Initial password & forced change
    // ---------------------------------------------------------------------

    public function test_created_cashier_must_change_password_before_using_the_pos(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Kasir Baru',
                'email' => 'kasir4@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertCreated();

        $login = $this->postJson('/api/auth/login', [
            'email' => 'kasir4@example.com',
            'password' => 'password123',
        ])->assertOk();

        $cashierToken = $login->json('data.token');

        // POS/business endpoints are blocked until the password is rotated.
        $this->withHeaders($this->bearer($cashierToken))
            ->getJson('/api/categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // /me stays reachable.
        $this->withHeaders($this->bearer($cashierToken))
            ->getJson('/api/me')
            ->assertOk();

        // Wrong current password is rejected.
        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/auth/change-password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        // Correct rotation succeeds and clears the flag.
        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password123',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->withHeaders($this->bearer($cashierToken))
            ->getJson('/api/categories')
            ->assertOk();

        $cashier = User::query()->where('email', 'kasir4@example.com')->firstOrFail();
        $this->assertFalse($cashier->must_change_password);
        $this->assertTrue(Hash::check('new-password-123', $cashier->password));
    }

    public function test_registered_owner_is_not_forced_to_change_password(): void
    {
        $this->freePlan();

        $this->postJson('/api/auth/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'store_name' => 'Toko Owner',
            'store_slug' => 'toko-owner',
            'business_type' => 'retail',
        ])->assertCreated();

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertFalse($owner->must_change_password);
    }

    // ---------------------------------------------------------------------
    // Membership lifecycle, token invalidation & tenant isolation
    // ---------------------------------------------------------------------

    public function test_deactivated_member_token_cannot_access_the_store(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $cashier = $this->addMember($store, StoreRole::CASHIER->value);
        $membership = $this->membershipOf($store, $cashier);
        $ownerToken = $this->issueToken($owner, $store);
        $cashierToken = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($cashierToken))
            ->getJson('/api/categories')
            ->assertOk();

        $this->withHeaders($this->bearer($ownerToken))
            ->patchJson('/api/current-store/members/'.$membership->id.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->withHeaders($this->bearer($cashierToken))
            ->getJson('/api/categories')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');

        $this->assertNull($this->currentStoreIdOnToken($cashierToken));

        // The global account is preserved.
        $this->assertDatabaseHas('users', ['id' => $cashier->id]);
    }

    public function test_role_change_applies_on_the_next_request(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $cashier = $this->addMember($store, StoreRole::CASHIER->value);
        $membership = $this->membershipOf($store, $cashier);
        $ownerToken = $this->issueToken($owner, $store);
        $cashierToken = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/categories', ['name' => 'Topi'])
            ->assertForbidden();

        $this->withHeaders($this->bearer($ownerToken))
            ->patchJson('/api/current-store/members/'.$membership->id.'/role', ['role' => 'admin'])
            ->assertOk();

        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/categories', ['name' => 'Topi'])
            ->assertCreated();

        // Demote back and the capability is removed again.
        $this->withHeaders($this->bearer($ownerToken))
            ->patchJson('/api/current-store/members/'.$membership->id.'/role', ['role' => 'cashier'])
            ->assertOk();

        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/categories', ['name' => 'Sepatu'])
            ->assertForbidden();
    }

    public function test_admin_can_only_toggle_cashiers(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $admin = $this->addMember($store, StoreRole::ADMIN->value);
        $cashier = $this->addMember($store, StoreRole::CASHIER->value);
        $adminMembership = $this->membershipOf($store, $admin);
        $cashierMembership = $this->membershipOf($store, $cashier);
        $token = $this->issueToken($admin, $store);

        // Admin may deactivate a cashier.
        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$cashierMembership->id.'/status', ['is_active' => false])
            ->assertOk();

        // Admin may not deactivate themselves (an admin), nor change roles.
        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$adminMembership->id.'/status', ['is_active' => false])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/current-store/members/'.$cashierMembership->id.'/role', ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_member_resources_are_tenant_scoped(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $memberB = $this->addMember($storeB, StoreRole::CASHIER->value);
        $membershipB = $this->membershipOf($storeB, $memberB);
        $tokenA = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/current-store/members/'.$membershipB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($tokenA))
            ->patchJson('/api/current-store/members/'.$membershipB->id.'/role', ['role' => 'admin'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($tokenA))
            ->patchJson('/api/current-store/members/'.$membershipB->id.'/status', ['is_active' => false])
            ->assertNotFound();

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/current-store/members')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_id', $ownerA->id);
    }

    public function test_client_supplied_store_id_is_ignored_on_creation(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $tokenA = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($tokenA))
            ->postJson('/api/current-store/members/cashiers', [
                'name' => 'Kasir A',
                'email' => 'kasir-a@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'store_id' => $storeB->id,
            ])
            ->assertCreated();

        $cashier = User::query()->where('email', 'kasir-a@example.com')->firstOrFail();

        $this->assertDatabaseHas('store_user', [
            'store_id' => $storeA->id,
            'user_id' => $cashier->id,
        ]);
        $this->assertDatabaseMissing('store_user', [
            'store_id' => $storeB->id,
            'user_id' => $cashier->id,
        ]);
    }

    public function test_inactive_store_cannot_be_used_for_member_management(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $store->update(['is_active' => false]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store/members')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');
    }

    public function test_member_list_never_exposes_password_material(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/current-store/members')
            ->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('password', $row);
        $this->assertStringNotContainsString('$2y$', $response->getContent());
    }
}
