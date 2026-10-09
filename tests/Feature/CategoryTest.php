<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_category_endpoints_require_authentication(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
        $this->postJson('/api/categories', ['name' => 'X'])->assertUnauthorized();
        $this->getJson('/api/categories/1')->assertUnauthorized();
        $this->putJson('/api/categories/1', ['name' => 'X'])->assertUnauthorized();
        $this->patchJson('/api/categories/1', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/categories/1')->assertUnauthorized();
    }

    public function test_token_without_current_store_is_rejected(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_user_cannot_use_a_store_they_are_not_a_member_of(): void
    {
        [$userA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeB);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');
    }

    public function test_owner_can_create_a_category(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', [
                'name' => 'Minuman',
                'description' => 'Semua minuman',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Minuman')
            ->assertJsonPath('data.description', 'Semua minuman')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'description', 'is_active', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('categories', [
            'store_id' => $store->id,
            'name' => 'Minuman',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_and_update_a_category(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $admin = User::factory()->create();
        $this->attachMember($admin, $store, StoreRole::ADMIN->value, true);
        $token = $this->issueToken($admin, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Makanan']);

        $created->assertCreated();
        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$id, ['name' => 'Makanan & Snack'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Makanan & Snack');
    }

    public function test_cashier_can_read_but_cannot_mutate_categories(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $category = Category::factory()->for($store, 'store')->create(['name' => 'Retail']);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Retail');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories/'.$category->id)
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Baru'])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$category->id, ['name' => 'Diubah'])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/categories/'.$category->id, ['is_active' => false])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/categories/'.$category->id)
            ->assertForbidden();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Retail',
            'is_active' => true,
        ]);
    }

    public function test_index_only_returns_categories_of_the_current_store(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        Category::factory()->for($storeA, 'store')->create(['name' => 'A-1']);
        Category::factory()->for($storeB, 'store')->create(['name' => 'B-1']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A-1');
    }

    public function test_other_tenant_category_is_not_accessible(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $categoryB = Category::factory()->for($storeB, 'store')->create(['name' => 'B']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories/'.$categoryB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$categoryB->id, ['name' => 'X'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/categories/'.$categoryB->id, ['is_active' => false])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/categories/'.$categoryB->id)
            ->assertNotFound();

        $this->assertDatabaseHas('categories', [
            'id' => $categoryB->id,
            'name' => 'B',
        ]);
    }

    public function test_duplicate_name_in_same_store_is_rejected(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        Category::factory()->for($store, 'store')->create(['name' => 'Minuman']);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Minuman'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_same_name_is_allowed_in_different_stores(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        $this->withHeaders($this->bearer($this->issueToken($userA, $storeA)))
            ->postJson('/api/categories', ['name' => 'Minuman'])
            ->assertCreated();

        $this->withHeaders($this->bearer($this->issueToken($userB, $storeB)))
            ->postJson('/api/categories', ['name' => 'Minuman'])
            ->assertCreated();

        $this->assertDatabaseCount('categories', 2);
    }

    public function test_update_allows_keeping_its_own_name(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $category = Category::factory()->for($store, 'store')->create(['name' => 'Minuman']);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$category->id, [
                'name' => 'Minuman',
                'description' => 'Tetap',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Minuman')
            ->assertJsonPath('data.description', 'Tetap');
    }

    public function test_update_rejects_name_already_used_by_another_category(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        Category::factory()->for($store, 'store')->create(['name' => 'Minuman']);
        $other = Category::factory()->for($store, 'store')->create(['name' => 'Makanan']);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$other->id, ['name' => 'Minuman'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_put_requires_name_but_patch_is_partial(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $category = Category::factory()->for($store, 'store')->create([
            'name' => 'Minuman',
            'description' => 'Awal',
            'is_active' => true,
        ]);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/categories/'.$category->id, ['description' => 'Tanpa nama'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/categories/'.$category->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Minuman')
            ->assertJsonPath('data.description', 'Awal')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_index_supports_pagination_search_and_active_filter(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        foreach (range(1, 30) as $i) {
            Category::factory()->for($store, 'store')->create([
                'name' => sprintf('Kategori %02d', $i),
            ]);
        }

        Category::factory()->for($store, 'store')->inactive()->create([
            'name' => 'Nonaktif Satu',
        ]);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 31);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?search=Nonaktif')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nonaktif Satu');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?is_active=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 30);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nonaktif Satu');
    }

    public function test_index_validates_pagination_and_filter_parameters(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?is_active=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/categories?sort=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    public function test_store_validation_rejects_invalid_payloads(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => str_repeat('a', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Valid', 'is_active' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', ['name' => 'Valid', 'description' => ['array']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');
    }

    public function test_client_supplied_store_id_does_not_change_ownership(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/categories', [
                'name' => 'Coba',
                'store_id' => $storeB->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('categories', [
            'name' => 'Coba',
            'store_id' => $storeA->id,
        ]);

        $this->assertDatabaseMissing('categories', [
            'name' => 'Coba',
            'store_id' => $storeB->id,
        ]);
    }

    public function test_deleting_a_store_cascades_its_categories(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $category = Category::factory()->for($store, 'store')->create();

        $store->delete();

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_category_requires_a_valid_store_reference(): void
    {
        $this->expectException(QueryException::class);

        Category::create([
            'store_id' => 999999,
            'name' => 'Ghost',
        ]);
    }
}
