<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\StockLocationProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Idempotent order creation.
 *
 * The SQLite test suite cannot run true parallel requests (no SELECT ... FOR
 * UPDATE), so these tests exercise the deterministic invariant the row lock
 * protects: a replayed (key, payload) resolves to the exact same order without
 * duplicating the order or its stock reservation. Real multi-process
 * concurrency is proven separately by `tests/Concurrency/run.php` on MySQL.
 */
class OrderIdempotencyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeItem(Store $store, array $overrides = []): Item
    {
        return Item::factory()->for($store, 'store')->create(array_merge([
            'name' => 'Kopi '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => 18000,
            'unit' => 'pcs',
            'is_active' => true,
        ], $overrides));
    }

    public function test_replaying_the_same_key_and_payload_returns_the_same_order(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $item = $this->makeItem($store);

        $payload = [
            'idempotency_key' => 'checkout-abc',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ];

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', $payload)
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_same_key_with_a_different_payload_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $item = $this->makeItem($store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => 'checkout-conflict',
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => 'checkout-conflict',
                'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_reordered_items_still_match_the_original_request(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $a = $this->makeItem($store, ['selling_price' => 10000]);
        $b = $this->makeItem($store, ['selling_price' => 5000]);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => 'checkout-reorder',
                'items' => [
                    ['item_id' => $a->id, 'quantity' => 1],
                    ['item_id' => $b->id, 'quantity' => 3],
                ],
            ])
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', [
                'idempotency_key' => 'checkout-reorder',
                'items' => [
                    ['item_id' => $b->id, 'quantity' => 3],
                    ['item_id' => $a->id, 'quantity' => 1],
                ],
            ])
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_orders_without_a_key_behave_as_before(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $item = $this->makeItem($store);

        $payload = ['items' => [['item_id' => $item->id, 'quantity' => 1]]];

        $this->withHeaders($this->bearer($token))->postJson('/api/orders', $payload)->assertCreated();
        $this->withHeaders($this->bearer($token))->postJson('/api/orders', $payload)->assertCreated();

        // No key means no idempotency contract: two distinct orders.
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_idempotency_key_is_scoped_per_store(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();
        $itemA = $this->makeItem($storeA);
        $itemB = $this->makeItem($storeB);

        $payload = fn (int $itemId): array => [
            'idempotency_key' => 'shared-key',
            'items' => [['item_id' => $itemId, 'quantity' => 1]],
        ];

        $this->withHeaders($this->bearer($this->issueToken($ownerA, $storeA)))
            ->postJson('/api/orders', $payload($itemA->id))
            ->assertCreated();

        $this->withHeaders($this->bearer($this->issueToken($ownerB, $storeB)))
            ->postJson('/api/orders', $payload($itemB->id))
            ->assertCreated();

        $this->assertSame(1, Order::query()->where('store_id', $storeA->id)->count());
        $this->assertSame(1, Order::query()->where('store_id', $storeB->id)->count());
    }

    public function test_idempotent_retry_does_not_reserve_stock_twice(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        app(StockLocationProvisioner::class)->ensureDefaultForStore($store);

        $item = $this->makeItem($store, ['tracks_stock' => true]);
        $location = $store->stockLocations()->where('is_default', true)->firstOrFail();

        StockBalance::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $location->id,
            'item_id' => $item->id,
            'quantity_on_hand' => '10.000',
            'quantity_reserved' => '0.000',
        ]);

        $token = $this->issueToken($owner, $store);
        $payload = [
            'idempotency_key' => 'checkout-stock',
            'items' => [['item_id' => $item->id, 'quantity' => 2]],
        ];

        $first = $this->withHeaders($this->bearer($token))->postJson('/api/orders', $payload)->assertCreated();
        $second = $this->withHeaders($this->bearer($token))->postJson('/api/orders', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $balance = StockBalance::query()->where('item_id', $item->id)->firstOrFail();
        $this->assertSame('2.000', $balance->quantity_reserved);

        $this->assertSame(1, Order::query()->where('store_id', $store->id)->count());
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('type', StockMovementType::RESERVATION->value)
                ->count(),
        );
    }
}
