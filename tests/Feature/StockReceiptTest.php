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

class StockReceiptTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'quantity' => '3.000',
            'idempotency_key' => 'receipt-'.bin2hex(random_bytes(6)),
        ], $overrides);
    }

    public function test_receipt_creates_a_balance_and_increases_on_hand(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'reference' => 'DO-001',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'purchase_in')
            ->assertJsonPath('data.movement.quantity', '3.000')
            ->assertJsonPath('data.balance.quantity_on_hand', '3.000')
            ->assertJsonPath('data.balance.quantity_reserved', '0.000')
            ->assertJsonPath('data.balance.quantity_available', '3.000');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_receipts_accumulate_and_keep_reserved_unchanged(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000', '4.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'quantity' => '2.500',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.balance.quantity_on_hand', '12.500')
            ->assertJsonPath('data.balance.quantity_reserved', '4.000')
            ->assertJsonPath('data.balance.quantity_available', '8.500');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'quantity' => '1.500',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.balance.quantity_on_hand', '14.000');

        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_receipt_is_idempotent_on_retry(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $payload = $this->payload([
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
        ]);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $payload)
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $payload)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.movement.id', $first->json('data.movement.id'))
            ->assertJsonPath('data.balance.quantity_on_hand', '3.000');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_receipt_rejects_zero_and_negative_quantity(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $token = $this->issueToken($owner, $store);

        $base = ['stock_location_id' => $this->defaultLocation($store)->id, 'item_id' => $item->id];

        foreach (['0', '-2'] as $bad) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/stock/receipts', $base + [
                    'quantity' => $bad,
                    'idempotency_key' => 'bad-'.bin2hex(random_bytes(4)),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');
        }
    }

    public function test_receipt_rejects_non_tracked_item_and_foreign_resources(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $untracked = $this->trackedItem($store, ['tracks_stock' => false]);
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $untracked->id,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'inventory_item_not_tracked');

        [, $otherStore] = $this->inventoryStore();
        $foreignItem = $this->trackedItem($otherStore);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $foreignItem->id,
            ]))
            ->assertNotFound();
    }

    public function test_cashier_cannot_record_a_receipt(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/receipts', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertForbidden();
    }

    public function test_ledger_failure_rolls_back_the_receipt(): void
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
                ->postJson('/api/stock/receipts', $this->payload([
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
