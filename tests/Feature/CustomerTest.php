<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'email' => 'budi@example.com',
            'address' => 'Jl. Merdeka 1',
            'notes' => null,
        ], $overrides);
    }

    public function test_customer_endpoints_require_authentication(): void
    {
        $this->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/customers', $this->payload())->assertUnauthorized();
        $this->getJson('/api/customers/1')->assertUnauthorized();
        $this->putJson('/api/customers/1', $this->payload())->assertUnauthorized();
        $this->patchJson('/api/customers/1', ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson('/api/customers/1')->assertUnauthorized();
    }

    public function test_token_without_current_store_is_rejected(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_owner_can_manage_customers(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', $this->payload());

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Budi Santoso')
            ->assertJsonPath('data.phone', '081234567890')
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'phone', 'email', 'address', 'notes', 'created_at', 'updated_at'],
            ]);

        $id = $created->json('data.id');

        $this->assertDatabaseHas('customers', [
            'id' => $id,
            'store_id' => $store->id,
            'name' => 'Budi Santoso',
        ]);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/customers/'.$id, $this->payload(['name' => 'Budi S.']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi S.');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/customers/'.$id, ['phone' => '0899999999'])
            ->assertOk()
            ->assertJsonPath('data.phone', '0899999999');
    }

    public function test_cashier_can_read_but_cannot_mutate_customers(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $customer = Customer::factory()->for($store, 'store')->create(['name' => 'Sari']);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sari');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers/'.$customer->id)
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', $this->payload())
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/customers/'.$customer->id, $this->payload())
            ->assertForbidden();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/customers/'.$customer->id)
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_index_only_returns_customers_of_the_current_store(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [$userB, $storeB] = $this->createOwnerWithStore();

        Customer::factory()->for($storeA, 'store')->create(['name' => 'A-1']);
        Customer::factory()->for($storeB, 'store')->create(['name' => 'B-1']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A-1');
    }

    public function test_other_tenant_customer_is_not_accessible(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $customerB = Customer::factory()->for($storeB, 'store')->create(['name' => 'B']);

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers/'.$customerB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/customers/'.$customerB->id, $this->payload(['name' => 'X']))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/customers/'.$customerB->id)
            ->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $customerB->id, 'name' => 'B']);
    }

    public function test_client_supplied_store_id_does_not_change_ownership(): void
    {
        [$userA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $token = $this->issueToken($userA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', $this->payload([
                'name' => 'Pindah',
                'store_id' => $storeB->id,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('customers', ['name' => 'Pindah', 'store_id' => $storeA->id]);
        $this->assertDatabaseMissing('customers', ['name' => 'Pindah', 'store_id' => $storeB->id]);
    }

    public function test_index_supports_search_and_pagination(): void
    {
        [$user, $store] = $this->createOwnerWithStore();

        Customer::factory()->for($store, 'store')->create([
            'name' => 'Andi Wijaya',
            'phone' => '0811111111',
            'email' => 'andi@example.com',
        ]);
        Customer::factory()->for($store, 'store')->create([
            'name' => 'Bunga Lestari',
            'phone' => '0822222222',
            'email' => 'bunga@example.com',
        ]);
        Customer::factory()->count(3)->for($store, 'store')->create();

        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers?search=Andi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Andi Wijaya');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers?search=0822222222')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bunga Lestari');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers?search=bunga@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bunga Lestari');
    }

    public function test_customer_validation(): void
    {
        [$user, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/customers', $this->payload(['name' => str_repeat('a', 151)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_deleting_a_customer_archives_it_and_keeps_order_history(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $customer = Customer::factory()->for($store, 'store')->create(['name' => 'Pelanggan Setia']);
        $order = Order::factory()->for($store, 'store')->create([
            'customer_id' => $customer->id,
        ]);

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/customers/'.$customer->id)
            ->assertOk();

        // Archived, not removed.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        // Order history still resolves the archived customer.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Pelanggan Setia');

        // Archived customer is no longer accessible/selectable.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers/'.$customer->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
