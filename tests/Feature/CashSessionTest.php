<?php

namespace Tests\Feature;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\ItemType;
use App\Enums\StoreRole;
use App\Models\CashSession;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CashSessionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function openViaApi(string $token, int $openingCash = 0, ?string $notes = null)
    {
        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/open', [
                'opening_cash' => $openingCash,
                'opening_notes' => $notes,
            ]);
    }

    private function makeOrderId(Store $store, string $token, int $price = 10000): int
    {
        $item = Item::factory()->for($store, 'store')->create([
            'name' => 'Item '.fake()->unique()->numerify('####'),
            'type' => ItemType::PRODUCT->value,
            'selling_price' => $price,
            'unit' => 'pcs',
            'is_active' => true,
        ]);

        return $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders', ['items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_shift_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cash-sessions/current')->assertUnauthorized();
        $this->getJson('/api/cash-sessions')->assertUnauthorized();
        $this->postJson('/api/cash-sessions/open', ['opening_cash' => 0])->assertUnauthorized();
        $this->getJson('/api/cash-sessions/1')->assertUnauthorized();
        $this->postJson('/api/cash-sessions/1/close', ['actual_cash' => 0])->assertUnauthorized();
        $this->getJson('/api/cash-sessions/1/movements')->assertUnauthorized();
        $this->postJson('/api/cash-sessions/1/movements', ['type' => 'cash_in', 'amount' => 1, 'reason' => 'x'])->assertUnauthorized();
    }

    public function test_shift_endpoints_require_current_store(): void
    {
        [$user] = $this->createOwnerWithStore();
        $token = $this->issueToken($user, null);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions')
            ->assertStatus(409)
            ->assertJsonPath('code', 'current_store_not_selected');
    }

    public function test_owner_can_open_a_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->openViaApi($token, 100000, 'Modal pagi')
            ->assertCreated()
            ->assertJsonPath('data.status', CashSessionStatus::OPEN->value)
            ->assertJsonPath('data.opening_cash', 100000)
            ->assertJsonPath('data.expected_cash', 100000)
            ->assertJsonPath('data.cashier_id', $owner->id)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id', 'cashier_id', 'status', 'opening_cash', 'cash_in_total',
                    'cash_out_total', 'cash_sales_total', 'expected_cash',
                    'actual_cash', 'difference', 'opened_at', 'closed_at',
                ],
            ]);

        $this->assertDatabaseHas('cash_sessions', [
            'store_id' => $store->id,
            'cashier_id' => $owner->id,
            'status' => CashSessionStatus::OPEN->value,
            'open_guard' => $store->id.':'.$owner->id,
        ]);
    }

    public function test_negative_opening_cash_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->openViaApi($token, -1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opening_cash');
    }

    public function test_second_open_shift_for_same_cashier_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->openViaApi($token, 10000)->assertCreated();

        $this->openViaApi($token, 20000)
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_already_open');
    }

    public function test_different_cashiers_can_open_shifts_in_the_same_store(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);

        $ownerToken = $this->issueToken($owner, $store);
        $cashierToken = $this->issueToken($cashier, $store);

        $this->openViaApi($ownerToken, 10000)->assertCreated();
        $this->openViaApi($cashierToken, 20000)->assertCreated();

        $this->assertDatabaseCount('cash_sessions', 2);
    }

    public function test_current_returns_null_then_the_open_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.current_cash_session', null);

        $this->openViaApi($token, 50000)->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.current_cash_session.opening_cash', 50000)
            ->assertJsonPath('data.current_cash_session.status', CashSessionStatus::OPEN->value);
    }

    public function test_shift_can_be_closed_and_cannot_be_closed_twice(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $sessionId = $this->openViaApi($token, 100000)->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', [
                'actual_cash' => 98000,
                'closing_notes' => 'Kurang 2000',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CashSessionStatus::CLOSED->value)
            ->assertJsonPath('data.expected_cash', 100000)
            ->assertJsonPath('data.actual_cash', 98000)
            ->assertJsonPath('data.difference', -2000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', ['actual_cash' => 100000])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_already_closed');
    }

    public function test_cash_movements_in_and_out_update_expected_cash(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $sessionId = $this->openViaApi($token, 100000)->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => CashMovementType::CASH_IN->value,
                'amount' => 50000,
                'reason' => 'Tambahan modal',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'cash_in')
            ->assertJsonPath('data.amount', 50000)
            ->assertJsonPath('data.user_id', $owner->id);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => CashMovementType::CASH_OUT->value,
                'amount' => 20000,
                'reason' => 'Beli galon',
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.cash_in_total', 50000)
            ->assertJsonPath('data.cash_out_total', 20000)
            ->assertJsonPath('data.expected_cash', 130000);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId.'/movements')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_movement_validation(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_in', 'amount' => 0, 'reason' => 'x',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_out', 'amount' => -5, 'reason' => 'x',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_in', 'amount' => 1000,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'nope', 'amount' => 1000, 'reason' => 'x',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_client_supplied_user_id_is_ignored_on_movement(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $other = User::factory()->create();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_in',
                'amount' => 10000,
                'reason' => 'Top up',
                'user_id' => $other->id,
                'store_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $owner->id);

        $this->assertDatabaseHas('cash_movements', [
            'cash_session_id' => $sessionId,
            'user_id' => $owner->id,
            'store_id' => $store->id,
        ]);
    }

    public function test_movement_is_not_editable_or_deletable_via_api(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->deleteJson('/api/cash-sessions/'.$sessionId.'/movements')
            ->assertStatus(405);
    }

    public function test_closed_shift_rejects_new_movements(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 10000)->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', ['actual_cash' => 10000])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_in', 'amount' => 1000, 'reason' => 'x',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_closed');
    }

    public function test_cashier_cannot_access_another_cashiers_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();
        $this->attachMember($cashierA, $store, StoreRole::CASHIER->value, true);
        $this->attachMember($cashierB, $store, StoreRole::CASHIER->value, true);

        $tokenA = $this->issueToken($cashierA, $store);
        $tokenB = $this->issueToken($cashierB, $store);

        $sessionA = $this->openViaApi($tokenA, 10000)->json('data.id');

        // Different cashier, same store: hidden by policy.
        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/cash-sessions/'.$sessionA)
            ->assertForbidden();

        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/cash-sessions/'.$sessionA.'/movements')
            ->assertForbidden();

        $this->withHeaders($this->bearer($tokenB))
            ->postJson('/api/cash-sessions/'.$sessionA.'/movements', [
                'type' => 'cash_in', 'amount' => 1000, 'reason' => 'x',
            ])
            ->assertForbidden();

        // B's list only contains B's own shifts.
        $this->withHeaders($this->bearer($tokenB))
            ->getJson('/api/cash-sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_owner_can_view_cashier_shift_in_same_store(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);

        $ownerToken = $this->issueToken($owner, $store);
        $cashierToken = $this->issueToken($cashier, $store);

        $sessionId = $this->openViaApi($cashierToken, 10000)->json('data.id');

        $this->withHeaders($this->bearer($ownerToken))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.cashier_id', $cashier->id);

        $this->withHeaders($this->bearer($ownerToken))
            ->getJson('/api/cash-sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Owner cannot record a movement on the cashier's shift.
        $this->withHeaders($this->bearer($ownerToken))
            ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                'type' => 'cash_in', 'amount' => 1000, 'reason' => 'x',
            ])
            ->assertForbidden();
    }

    public function test_shift_tenant_isolation(): void
    {
        [$ownerA, $storeA] = $this->createOwnerWithStore();
        [$ownerB, $storeB] = $this->createOwnerWithStore();

        $tokenA = $this->issueToken($ownerA, $storeA);
        $tokenB = $this->issueToken($ownerB, $storeB);

        $sessionB = $this->openViaApi($tokenB, 10000)->json('data.id');

        $this->withHeaders($this->bearer($tokenA))
            ->getJson('/api/cash-sessions/'.$sessionB)
            ->assertNotFound();

        $this->withHeaders($this->bearer($tokenA))
            ->postJson('/api/cash-sessions/'.$sessionB.'/movements', [
                'type' => 'cash_in', 'amount' => 1000, 'reason' => 'x',
            ])
            ->assertNotFound();
    }

    public function test_full_cash_calculation_and_difference(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $sessionId = $this->openViaApi($token, 100000)->json('data.id');

        // Cash in 50k, cash out 20k.
        foreach ([['cash_in', 50000], ['cash_out', 20000]] as [$type, $amount]) {
            $this->withHeaders($this->bearer($token))
                ->postJson('/api/cash-sessions/'.$sessionId.'/movements', [
                    'type' => $type, 'amount' => $amount, 'reason' => $type,
                ])
                ->assertCreated();
        }

        // Valid cash payment 30k, and a non-cash payment 40k that must not count.
        $orderId = $this->makeOrderId($store, $token, 1000000);
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 30000,
            ])
            ->assertCreated();

        $order2 = $this->makeOrderId($store, $token, 1000000);
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$order2.'/payments', [
                'payment_method' => 'bank_transfer', 'amount' => 40000,
            ])
            ->assertCreated();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.cash_in_total', 50000)
            ->assertJsonPath('data.cash_out_total', 20000)
            ->assertJsonPath('data.cash_sales_total', 30000)
            ->assertJsonPath('data.expected_cash', 160000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', ['actual_cash' => 155000])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 160000)
            ->assertJsonPath('data.actual_cash', 155000)
            ->assertJsonPath('data.difference', -5000)
            ->assertJsonPath('data.cash_sales_total', 30000);
    }

    public function test_voided_cash_payment_reduces_cash_sales(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $orderId = $this->makeOrderId($store, $token, 10000);
        $paymentId = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertJsonPath('data.expected_cash', 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', ['reason' => 'Salah'])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertJsonPath('data.cash_sales_total', 0)
            ->assertJsonPath('data.expected_cash', 0);
    }

    public function test_voided_cash_payment_on_closed_shift_is_rejected(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $orderId = $this->makeOrderId($store, $token, 10000);
        $paymentId = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', ['actual_cash' => 10000])
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/payments/'.$paymentId.'/void', ['reason' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_closed');

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'completed']);
    }

    public function test_legacy_cash_payment_without_shift_is_not_attributed(): void
    {
        // A cash payment recorded before this phase (no cash_session_id) must
        // not be counted into a later shift.
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $orderId = $this->makeOrderId($store, $token, 10000);
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertCreated();

        // Remove the attribution to emulate a legacy row.
        Payment::query()
            ->where('order_id', $orderId)
            ->update(['cash_session_id' => null]);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/cash-sessions/'.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.cash_sales_total', 0)
            ->assertJsonPath('data.expected_cash', 0);
    }

    public function test_cash_payment_requires_an_open_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_required');
    }

    public function test_cash_payment_is_linked_to_the_recorders_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);
        $sessionId = $this->openViaApi($token, 0)->json('data.id');

        $orderId = $this->makeOrderId($store, $token, 10000);
        $paymentId = $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'cash_session_id' => $sessionId,
        ]);
    }

    public function test_cashier_cannot_record_cash_on_another_cashiers_shift(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();

        $cashier = User::factory()->create();
        $this->attachMember($cashier, $store, StoreRole::CASHIER->value, true);

        // Owner has an open shift; the cashier does not.
        $this->openViaApi($this->issueToken($owner, $store), 10000)->assertCreated();

        $cashierToken = $this->issueToken($cashier, $store);
        $orderId = $this->makeOrderId($store, $cashierToken, 10000);

        $this->withHeaders($this->bearer($cashierToken))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_required');
    }

    public function test_closed_shift_rejects_new_cash_payments(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $token = $this->issueToken($owner, $store);

        $sessionId = $this->openViaApi($token, 0)->json('data.id');
        $this->withHeaders($this->bearer($token))
            ->postJson('/api/cash-sessions/'.$sessionId.'/close', ['actual_cash' => 0])
            ->assertOk();

        $orderId = $this->makeOrderId($store, $token, 10000);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/orders/'.$orderId.'/payments', [
                'payment_method' => 'cash', 'amount' => 10000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_session_required');
    }

    public function test_cashier_list_only_shows_own_shifts_and_filters_work(): void
    {
        [$owner, $store] = $this->createOwnerWithStore();
        $ownerToken = $this->issueToken($owner, $store);

        $this->openViaApi($ownerToken, 10000)->assertCreated();
        $this->withHeaders($this->bearer($ownerToken))
            ->postJson('/api/cash-sessions/'.CashSession::query()->first()->id.'/close', ['actual_cash' => 10000])
            ->assertOk();
        $this->openViaApi($ownerToken, 20000)->assertCreated();

        $this->withHeaders($this->bearer($ownerToken))
            ->getJson('/api/cash-sessions?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->bearer($ownerToken))
            ->getJson('/api/cash-sessions?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->bearer($ownerToken))
            ->getJson('/api/cash-sessions?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
    }
}
