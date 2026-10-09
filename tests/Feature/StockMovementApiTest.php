<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    public function test_stock_movement_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/stock/movements')->assertUnauthorized();
    }

    public function test_index_only_returns_movements_of_the_current_store(): void
    {
        [$ownerA, $storeA] = $this->inventoryStore();
        $itemA = $this->trackedItem($storeA);
        $this->movement($storeA, $itemA, StockMovementType::OPENING, '5.000');

        [, $storeB] = $this->inventoryStore();
        $itemB = $this->trackedItem($storeB);
        $this->movement($storeB, $itemB, StockMovementType::OPENING, '9.000');

        $token = $this->issueToken($ownerA, $storeA);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $itemA->id)
            ->assertJsonPath('data.0.type', StockMovementType::OPENING->value);
    }

    public function test_cashier_can_read_the_ledger(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->movement($store, $item, StockMovementType::OPENING, '5.000');

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_item_location_type_and_order(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $itemA = $this->trackedItem($store);
        $itemB = $this->trackedItem($store);
        $location = $this->defaultLocation($store);

        $this->movement($store, $itemA, StockMovementType::OPENING, '5.000');
        $this->movement($store, $itemB, StockMovementType::SALE_OUT, '1.000');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?item_id='.$itemA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $itemA->id);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?stock_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?type='.StockMovementType::SALE_OUT->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $itemB->id);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?type=not_a_type')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_index_filters_by_occurred_at_range_and_paginates(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);

        $this->movement($store, $item, StockMovementType::OPENING, '1.000', '2026-01-10 09:00:00');
        $this->movement($store, $item, StockMovementType::OPENING, '2.000', '2026-02-10 09:00:00');
        $this->movement($store, $item, StockMovementType::OPENING, '3.000', '2026-03-10 09:00:00');

        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '2.000');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/stock/movements?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_ledger_has_no_mutation_endpoints(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $token = $this->issueToken($owner, $store);

        // GET exists, so POST to the same URI is method-not-allowed.
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/movements', [])
            ->assertStatus(405);

        $this->withHeaders($this->bearer($token))
            ->patchJson('/api/stock/movements/1', [])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/stock/movements/1')
            ->assertNotFound();
    }

    public function test_movements_remain_append_only_at_the_model_boundary(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $movement = $this->movement($store, $item, StockMovementType::OPENING, '5.000');

        $this->expectException(LogicException::class);
        $movement->update(['note' => 'tampered']);
    }

    private function movement(
        Store $store,
        Item $item,
        StockMovementType $type,
        string $quantity,
        ?string $occurredAt = null,
    ): StockMovement {
        return StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'type' => $type->value,
            'quantity' => $quantity,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
