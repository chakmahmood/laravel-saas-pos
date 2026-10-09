<?php

namespace Tests\Feature;

use App\Enums\FulfillmentStatus;
use App\Enums\ItemType;
use App\Enums\PaymentStatus;
use App\Enums\StoreRole;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem($store, array $overrides = []): Item
    {
        return Item::factory()->for($store, 'store')->create(array_merge([
            'name' => 'Kopi '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 10000,
            'unit' => 'pcs',
            'is_active' => true,
        ], $overrides));
    }

    public function test_order_endpoints_require_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->postJson('/api/orders', ['items' => []])->assertUnauthorized();
        $this->getJson('/api/orders/1')->assertUnauthorized();
        $this->patchJson('/api/orders/1/fulfillment', ['fulfillment_status' => 'processing'])->assertUnauthorized();
    }

    public function test_token_without_current_store_is_rejected(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_owner_can_create_an_order_with_backend_computed_totals(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $item = $this->makeItem($store, ['selling_price' => 18000, 'name' => 'Kopi Susu']);

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 2],
                ],
                'tax_amount' => 3600,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 36000)
            ->assertJsonPath('data.tax_amount', 3600)
            ->assertJsonPath('data.total_amount', 39600)
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID->value)
            ->assertJsonPath('data.fulfillment_status', FulfillmentStatus::PENDING->value)
            ->assertJsonPath('data.items.0.item_name', 'Kopi Susu')
            ->assertJsonPath('data.items.0.unit_price', 18000)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.line_total', 36000);

        $this->assertMatchesRegularExpression(
            '/^TRX-\d{8}-\d{4}$/',
            $response->json('data.order_number'),
        );

        $this->assertDatabaseHas('orders', [
            'store_id' => $store->id,
            'cashier_id' => $owner->id,
            'total_amount' => 39600,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);

        $this->assertDatabaseHas('order_items', [
            'item_id' => $item->id,
            'item_name' => 'Kopi Susu',
            'unit_price' => 18000,
            'quantity' => 2,
        ]);
    }

    public function test_order_with_multiple_items_and_line_discount(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $a = $this->makeItem($store, ['selling_price' => 10000]);
        $b = $this->makeItem($store, ['selling_price' => 5000]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [
                    ['item_id' => $a->id, 'quantity' => 1, 'discount_amount' => 2000],
                    ['item_id' => $b->id, 'quantity' => 3],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 25000)
            ->assertJsonPath('data.discount_amount', 2000)
            ->assertJsonPath('data.total_amount', 23000)
            ->assertJsonPath('data.items.0.line_total', 8000)
            ->assertJsonPath('data.items.1.line_total', 15000);
    }

    public function test_client_supplied_prices_and_totals_are_ignored(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $item = $this->makeItem($store, ['selling_price' => 25000]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [
                    ['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1],
                ],
                'total_amount' => 1,
                'paid_amount' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 25000)
            ->assertJsonPath('data.items.0.unit_price', 25000);
    }

    public function test_item_from_another_store_is_rejected(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $itemB = $this->makeItem($storeB);

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $itemB->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.item_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_from_another_store_is_rejected(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();
        $customerB = Customer::factory()->for($storeB, 'store')->create();

        $item = $this->makeItem($storeA);
        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'customer_id' => $customerB->id,
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_inactive_item_is_rejected_for_a_new_order(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store, ['is_active' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.item_id');
    }

    public function test_quantity_and_money_validation(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => -1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1, 'discount_amount' => -5]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.discount_amount');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]], 'tax_amount' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_amount');
    }

    public function test_discount_cannot_exceed_line_subtotal(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store, ['selling_price' => 10000]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1, 'discount_amount' => 20000]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.discount_amount');
    }

    public function test_order_creation_is_atomic(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $valid = $this->makeItem($store);
        $foreign = $this->makeItem($storeB);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [
                    ['item_id' => $valid->id, 'quantity' => 1],
                    ['item_id' => $foreign->id, 'quantity' => 1],
                ],
            ])
            ->assertUnprocessable();

        // Nothing partially persisted.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_order_numbers_are_unique_per_store(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated();

        $this->assertNotSame($first->json('data.order_number'), $second->json('data.order_number'));
    }

    public function test_fulfillment_status_transitions(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        $order = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated();

        $id = $order->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', ['fulfillment_status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'processing');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', ['fulfillment_status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'completed');

        // completed is final.
        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', ['fulfillment_status' => 'processing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fulfillment_status');
    }

    public function test_cashier_cannot_cancel_but_can_advance_fulfillment(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', ['fulfillment_status' => 'processing'])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', ['fulfillment_status' => 'cancelled'])
            ->assertForbidden();
    }

    public function test_owner_can_cancel_an_unpaid_order(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        $created = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated();

        $id = $created->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$id.'/fulfillment', [
                'fulfillment_status' => 'cancelled',
                'reason' => 'Salah input',
            ])
            ->assertOk()
            ->assertJsonPath('data.fulfillment_status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Salah input');
    }

    public function test_other_tenant_order_is_not_accessible(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [, $storeB] = $this->createOwnerWithStore();

        $orderB = Order::factory()->for($storeB, 'store')->create();

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders/'.$orderB->id)
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/orders/'.$orderB->id.'/fulfillment', ['fulfillment_status' => 'processing'])
            ->assertNotFound();
    }

    public function test_index_filters_and_pagination(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
                ->assertCreated();
        }

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?payment_status=unpaid')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/orders?fulfillment_status=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_order_number_sequence_starts_at_one(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $item = $this->makeItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated()
            ->assertJsonPath('data.order_number', fn ($value) => (bool) preg_match('/-0001$/', $value));
    }

    public function test_money_overflow_is_rejected_instead_of_overflowing(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        // Max allowed catalog price (~1e15) times a large quantity overflows a
        // 64-bit integer product; it must be rejected, not silently wrapped.
        $item = $this->makeItem($store, ['selling_price' => 999_999_999_999_999]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 999_999]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_fractional_quantity_line_total(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $item = $this->makeItem($store, ['selling_price' => 25000, 'unit' => 'kg']);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'items' => [['item_id' => $item->id, 'quantity' => 1.5]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 37500)
            ->assertJsonPath('data.items.0.line_total', 37500);
    }
}
