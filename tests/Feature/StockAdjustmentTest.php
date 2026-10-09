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

class StockAdjustmentTest extends TestCase
{
    use InteractsWithInventory, InteractsWithTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'counted_quantity' => '10.000',
            'reason' => 'Hasil opname',
            'idempotency_key' => 'adjust-'.bin2hex(random_bytes(6)),
        ], $overrides);
    }

    public function test_adjustment_up_records_an_adjustment_in(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '15.000',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'adjustment_in')
            ->assertJsonPath('data.movement.quantity', '5.000')
            ->assertJsonPath('data.balance.quantity_on_hand', '15.000')
            ->assertJsonPath('data.balance.quantity_reserved', '0.000');
    }

    public function test_adjustment_down_records_an_adjustment_out(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '6.000',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.movement.type', 'adjustment_out')
            ->assertJsonPath('data.movement.quantity', '4.000')
            ->assertJsonPath('data.balance.quantity_on_hand', '6.000');
    }

    public function test_adjustment_with_zero_delta_is_a_no_op(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '10.000',
            ]))
            ->assertOk()
            ->assertJsonPath('data.no_op', true)
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.movement', null)
            ->assertJsonPath('data.balance.quantity_on_hand', '10.000');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustment_below_reserved_is_rejected(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000', '6.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '5.000',
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'adjustment_below_reserved');

        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item->id,
            'quantity_on_hand' => '10.000',
            'quantity_reserved' => '6.000',
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustment_is_idempotent_on_retry(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $payload = $this->payload([
            'stock_location_id' => $this->defaultLocation($store)->id,
            'item_id' => $item->id,
            'counted_quantity' => '15.000',
            'idempotency_key' => 'adjust-fixed',
        ]);

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $payload)
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $payload)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.movement.id', $first->json('data.movement.id'))
            ->assertJsonPath('data.balance.quantity_on_hand', '15.000');

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_adjustment_rejects_a_count_below_zero(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '-1',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counted_quantity');
    }

    public function test_adjustment_requires_a_reason(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', [
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
                'counted_quantity' => '15.000',
                'idempotency_key' => 'adjust-no-reason',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_cashier_cannot_record_an_adjustment(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);
        $token = $this->issueToken($cashier, $store);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/stock/adjustments', $this->payload([
                'stock_location_id' => $this->defaultLocation($store)->id,
                'item_id' => $item->id,
            ]))
            ->assertForbidden();
    }

    public function test_ledger_failure_rolls_back_the_adjustment(): void
    {
        [$owner, $store] = $this->inventoryStore();
        $item = $this->trackedItem($store);
        $this->setStock($store, $item, '10.000');
        $token = $this->issueToken($owner, $store);

        StockMovement::creating(function (): void {
            throw new RuntimeException('forced ledger failure');
        });

        try {
            $this->withoutExceptionHandling()
                ->withHeaders($this->bearer($token))
                ->postJson('/api/stock/adjustments', $this->payload([
                    'stock_location_id' => $this->defaultLocation($store)->id,
                    'item_id' => $item->id,
                    'counted_quantity' => '15.000',
                ]));

            $this->fail('Expected the forced ledger failure.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseHas('stock_balances', [
            'item_id' => $item->id,
            'quantity_on_hand' => '10.000',
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
