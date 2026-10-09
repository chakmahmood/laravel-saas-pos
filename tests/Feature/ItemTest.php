<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\CatalogItemService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kopi Susu',
            'type' => ItemType::MENU->value,
            'selling_price' => 18000,
            'unit' => 'porsi',
        ], $overrides);
    }

    private function subscribe(Store $store, ?int $maxProducts, string $status = 'active'): Plan
    {
        $plan = Plan::factory()->create(['max_products' => $maxProducts]);

        $store->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        return $plan;
    }

    public function test_item_endpoints_require_authentication(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();
        $this->postJson('/api/items', $this->itemPayload())->assertUnauthorized();
        $this->getJson('/api/items/1')->assertUnauthorized();
        $this->putJson('/api/items/1', $this->itemPayload())->assertUnauthorized();
        $this->patchJson('/api/items/1', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/items/1')->assertUnauthorized();
    }

    public function test_token_without_current_store_is_rejected(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_user_cannot_use_a_store_they_are_not_a_member_of(): void
    {
        [$userA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeB);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_unavailable');
    }

    public function test_owner_can_manage_items(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload());

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Kopi Susu')
            ->assertJsonPath('data.type', ItemType::MENU->value)
            ->assertJsonPath('data.selling_price', 18000)
            ->assertJsonPath('data.cost_price', null)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id', 'category_id', 'name', 'type', 'sku', 'barcode',
                    'description', 'cost_price', 'selling_price', 'unit',
                    'is_active', 'created_at', 'updated_at',
                ],
            ]);

        $id = $created->json('data.id');

        $this->assertDatabaseHas('items', [
            'id' => $id,
            'store_id' => $store->id,
            'name' => 'Kopi Susu',
            'type' => ItemType::MENU->value,
        ]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/items/'.$id, $this->itemPayload(['name' => 'Kopi Susu Gula Aren']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Kopi Susu Gula Aren');

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$id)
            ->assertOk();

        $this->assertDatabaseMissing('items', ['id' => $id]);
    }

    public function test_admin_can_manage_items(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $admin = User::factory()->create();
        $this->attachMember($admin, $store, StoreRole::ADMIN->value, true);
        $token = $this->issueToken($admin, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'Beras']));
        $created->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$id, ['selling_price' => 75000])
            ->assertOk()
            ->assertJsonPath('data.selling_price', 75000);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$id)
            ->assertOk();
    }

    public function test_cashier_can_read_but_cannot_mutate_items(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = Item::factory()->for($store, 'store')->create(['name' => 'Sample']);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sample');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$item->id)
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload())
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/items/'.$item->id, $this->itemPayload())
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$item->id, ['is_active' => false])
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$item->id)
            ->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_active' => true]);
    }

    public function test_index_only_returns_items_of_the_current_store(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        Item::factory()->for($storeA, 'store')->create(['name' => 'A-1']);
        Item::factory()->for($storeB, 'store')->create(['name' => 'B-1']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A-1');
    }

    public function test_other_tenant_item_is_not_accessible(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $itemB = Item::factory()->for($storeB, 'store')->create(['name' => 'B']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items/'.$itemB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/items/'.$itemB->id, $this->itemPayload())
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$itemB->id, ['name' => 'X'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/items/'.$itemB->id)
            ->assertNotFound();

        $this->assertDatabaseHas('items', ['id' => $itemB->id, 'name' => 'B']);
    }

    public function test_client_supplied_store_id_does_not_change_ownership(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload([
                'name' => 'Pindah',
                'store_id' => $storeB->id,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('items', ['name' => 'Pindah', 'store_id' => $storeA->id]);
        $this->assertDatabaseMissing('items', ['name' => 'Pindah', 'store_id' => $storeB->id]);
    }

    public function test_all_item_types_are_accepted(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        foreach (ItemType::cases() as $type) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/items', $this->itemPayload([
                    'name' => 'Item '.$type->value,
                    'type' => $type->value,
                ]))
                ->assertCreated()
                ->assertJsonPath('data.type', $type->value);

            $this->assertDatabaseHas('items', [
                'store_id' => $store->id,
                'type' => $type->value,
            ]);
        }
    }

    public function test_invalid_item_type_is_rejected(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['type' => 'spaceship']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_negative_prices_are_rejected(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['selling_price' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selling_price');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['cost_price' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cost_price');
    }

    public function test_cost_price_can_be_null(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['cost_price' => null]))
            ->assertCreated()
            ->assertJsonPath('data.cost_price', null);

        $this->assertDatabaseHas('items', ['cost_price' => null]);
    }

    public function test_sku_and_barcode_are_unique_per_store(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A', 'sku' => 'SKU-1']))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B', 'sku' => 'SKU-1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'C', 'barcode' => '111']))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'D', 'barcode' => '111']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
    }

    public function test_same_sku_is_allowed_in_different_stores(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        $this->withHeaders($this->bearer($this->issueToken($userA, $storeA)))
            ->postJson('/api/items', $this->itemPayload(['sku' => 'SAMA']))
            ->assertCreated();

        $this->withHeaders($this->bearer($this->issueToken($userB, $storeB)))
            ->postJson('/api/items', $this->itemPayload(['sku' => 'SAMA']))
            ->assertCreated();
    }

    public function test_many_items_without_sku_or_barcode_are_allowed(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        foreach (range(1, 3) as $i) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/items', $this->itemPayload([
                    'name' => 'Tanpa Kode '.$i,
                    'sku' => null,
                    'barcode' => null,
                ]))
                ->assertCreated();
        }

        $this->assertDatabaseCount('items', 3);
    }

    public function test_category_from_another_tenant_is_rejected(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $categoryB = Category::factory()->for($storeB, 'store')->create();

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['category_id' => $categoryB->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_item_without_category_is_allowed(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['category_id' => null]))
            ->assertCreated()
            ->assertJsonPath('data.category_id', null);
    }

    public function test_index_supports_search_filters_and_pagination(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $categoryA = Category::factory()->for($store, 'store')->create();
        $categoryB = Category::factory()->for($store, 'store')->create();

        Item::factory()->for($store, 'store')->ofType(ItemType::PRODUCT)->create([
            'name' => 'Beras Premium',
            'category_id' => $categoryA->id,
            'sku' => 'SKU-BERAS',
        ]);
        Item::factory()->for($store, 'store')->ofType(ItemType::MENU)->create([
            'name' => 'Nasi Goreng',
            'category_id' => $categoryB->id,
            'is_active' => false,
        ]);
        Item::factory()->for($store, 'store')->ofType(ItemType::SERVICE)->create([
            'name' => 'Servis Motor',
        ]);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?type=menu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nasi Goreng');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?category_id='.$categoryA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beras Premium');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nasi Goreng');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?search=Beras')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beras Premium');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?search=SKU-BERAS')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beras Premium');
    }

    public function test_index_validates_query_parameters(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?type=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?category_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/items?sort=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    public function test_quota_is_enforced_on_create(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $this->subscribe($store, 2);
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A']))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B']))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'C']))
            ->assertForbidden()
            ->assertJsonPath('code', 'item_limit_reached');

        $this->assertDatabaseCount('items', 2);
    }

    public function test_update_is_not_blocked_by_a_full_quota(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $this->subscribe($store, 1);
        $token = $this->issueToken($user, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A']))
            ->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$id, ['name' => 'A Updated', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'A Updated');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B']))
            ->assertForbidden()
            ->assertJsonPath('code', 'item_limit_reached');
    }

    public function test_inactive_items_count_toward_the_quota(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $this->subscribe($store, 1);
        $token = $this->issueToken($user, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A']))
            ->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/items/'.$id, ['is_active' => false])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B']))
            ->assertForbidden()
            ->assertJsonPath('code', 'item_limit_reached');
    }

    public function test_null_quota_means_unlimited(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $this->subscribe($store, null);
        $token = $this->issueToken($user, $store);

        foreach (range(1, 5) as $i) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/items', $this->itemPayload(['name' => 'Unlimited '.$i]))
                ->assertCreated();
        }

        $this->assertDatabaseCount('items', 5);
    }

    public function test_quota_uses_the_active_subscription_plan_only(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        // A non-active subscription with a very large limit must be ignored.
        $this->subscribe($store, 1000, 'cancelled');

        // The active subscription enforces a limit of 1.
        $this->subscribe($store, 1);

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A']))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B']))
            ->assertForbidden()
            ->assertJsonPath('code', 'item_limit_reached');
    }

    public function test_update_keeps_unique_rules_against_itself_and_others(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'A', 'sku' => 'S-1']))
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/items', $this->itemPayload(['name' => 'B', 'sku' => 'S-2']))
            ->assertCreated();

        $secondId = $second->json('data.id');

        // Keeping its own SKU is fine.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/items/'.$secondId, $this->itemPayload([
                'name' => 'B',
                'sku' => 'S-2',
            ]))
            ->assertOk();

        // Reusing another item's SKU is rejected.
        $this->withHeaders($this->bearer($token))
            ->putJson('/api/items/'.$secondId, $this->itemPayload([
                'name' => 'B',
                'sku' => 'S-1',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');

        $this->assertDatabaseHas('items', ['id' => $secondId, 'sku' => 'S-2']);
        $this->assertDatabaseHas('items', ['id' => $first->json('data.id'), 'sku' => 'S-1']);
    }

    public function test_database_rejects_duplicate_sku(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        Item::factory()->for($store, 'store')->create(['sku' => 'SKU-DUP']);

        $this->expectException(QueryException::class);

        Item::factory()->for($store, 'store')->create(['sku' => 'SKU-DUP']);
    }

    public function test_database_rejects_invalid_category_reference(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $this->expectException(QueryException::class);

        Item::create([
            'store_id' => $store->id,
            'category_id' => 999999,
            'name' => 'Ghost',
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 1000,
            'unit' => 'pcs',
        ]);
    }

    public function test_database_restricts_category_delete_when_used(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        $category = Category::factory()->for($store, 'store')->create();
        Item::factory()->for($store, 'store')->create(['category_id' => $category->id]);

        $this->expectException(QueryException::class);

        $category->delete();
    }

    public function test_catalog_service_translates_database_unique_violation(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        Item::factory()->for($store, 'store')->create(['sku' => 'RACE-DUP']);

        /** @var CatalogItemService $service */
        $service = $this->app->make(CatalogItemService::class);

        $this->expectException(ValidationException::class);

        // Bypasses form validation to exercise the database-race fallback.
        $service->create($store, [
            'name' => 'Race',
            'type' => ItemType::PRODUCT->value,
            'sku' => 'RACE-DUP',
            'selling_price' => 1000,
            'unit' => 'pcs',
            'is_active' => true,
        ]);
    }
}
