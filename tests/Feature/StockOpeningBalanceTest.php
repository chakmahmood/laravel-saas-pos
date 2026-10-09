<?php

namespace Tests\Feature;

use App\Enums\StoreRole;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithInventory;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StockOpeningBalanceTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'quantity' => '5.000',
            'idempotency_key' => 'opening-'.bin2hex(random_bytes(6)),
        ], $overrides);
    }

    public function test_owner_can_record_opening_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'opening')
            ->assertJsonPath('data.movement.quantity', '5.000')
            ->assertJsonPath('data.balance.quantity_on_hand', '5.000')
            ->assertJsonPath('data.balance.quantity_reserved', '0.000')
            ->assertJsonPath('data.balance.quantity_available', '5.000')
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.no_op', false);

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_balances', [
            'store_id' => $store->id,
            'item_id' => $item->id,
            'quantity_on_hand' => '5.000',
            'quantity_reserved' => '0.000',
        ]);
    }

    public function test_opening_stock_cannot_be_applied_twice(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $base = ['stock_location_id' => $this->defaultLocation($store)->id, 'item_id' => $item->id];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload($base))
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload($base))
            ->assertStatus(409)
            ->assertJsonPath('code', 'opening_stock_conflict');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_opening_stock_is_rejected_when_a_movement_exists(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        StockMovement::factory()->create([
            'store_id' => $store->id,
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'type' => 'purchase_in',
            'quantity' => '2.000',
        ]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'opening_stock_conflict');
    }

    public function test_opening_stock_is_idempotent_on_retry(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $payload = $this->payload([
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
        ]);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $payload)
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $payload)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.movement.id', $first->json('data.movement.id'))
            ->assertJsonPath('data.balance.quantity_on_hand', '5.000');

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_balances', 1);
    }

    public function test_reusing_an_idempotency_key_with_a_different_payload_is_rejected(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $base = [
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'idempotency_key' => 'opening-fixed-key',
        ];

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $base + ['quantity' => '5.000'])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $base + ['quantity' => '6.000'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_conflict');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_opening_stock_rejects_a_non_tracked_item(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store, ['tracks_stock' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'inventory_item_not_tracked');
    }

    public function test_opening_stock_hides_foreign_item_and_location(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        [, $otherStore] = $this->inventoryStore();
        $foreignItem = $this->trackedItem($otherStore);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $foreignItem->id,
            ]))
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($otherStore)->id,
                'item_id' => $item->id,
            ]))
            ->assertNotFound();
    }

    public function test_opening_stock_validates_quantity(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $base = ['stock_location_id' => $this->defaultLocation($store)->id, 'item_id' => $item->id];

        foreach (['0', '-1', '1.1234'] as $bad) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/stock/opening-balances', $base + [
                    'quantity' => $bad,
                    'idempotency_key' => 'bad-'.bin2hex(random_bytes(4)),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');
        }
    }

    public function test_cashier_cannot_record_opening_stock(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertForbidden();
    }

    public function test_non_inventory_store_cannot_record_opening_stock(): void
    {
        [$owner, $store] = $this->nonInventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/opening-balances', $this->payload([
                'item_id' => $item->id,
                'stock_location_id' => 1,
            ]))
            ->assertForbidden()
            ->assertJsonPath('code', 'inventory_not_available');
    }

    public function test_ledger_failure_rolls_back_the_opening_balance(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        StockMovement::creating(function (): void {
            throw new RuntimeException('forced ledger failure');
        });

        try {
            $this->withoutExceptionHandling()
                ->withHeaders($this->bearer($token))
                ->postJson('/api/stock/opening-balances', $this->payload([
                    'stock_location_id' => $this->defaultLocation($store)->id,
                    'item_id' => $item->id,
                ]));

            $this->fail('Expected the forced ledger failure.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
