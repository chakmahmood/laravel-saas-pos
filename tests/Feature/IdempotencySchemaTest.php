<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Schema-level audit for the order/payment idempotency migration, verified on
 * the isolated in-memory SQLite test database (never the development database).
 *
 * Proves the additive columns exist, the unique index really spans
 * (store_id, idempotency_key), legacy NULL rows are allowed, and a duplicate
 * (store_id, key) is rejected at the database level.
 */
class IdempotencySchemaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_orders_have_the_nullable_idempotency_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('orders', 'request_fingerprint'));
    }

    public function test_payments_have_the_nullable_idempotency_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'idempotency_key'));
        $this->assertTrue(Schema::hasColumn('payments', 'request_fingerprint'));
    }

    public function test_orders_unique_index_covers_store_id_and_idempotency_key(): void
    {
        $this->assertUniqueIndexSpansStoreAndKey('orders', 'orders_store_idempotency_key_unique');
    }

    public function test_payments_unique_index_covers_store_id_and_idempotency_key(): void
    {
        $this->assertUniqueIndexSpansStoreAndKey('payments', 'payments_store_idempotency_key_unique');
    }

    public function test_multiple_null_keys_are_allowed_for_legacy_rows(): void
    {
        [, $store] = $this->createOwnerWithStore();

        Order::factory()->for($store, 'store')->create(['order_number' => 'TRX-NULL-1']);
        Order::factory()->for($store, 'store')->create(['order_number' => 'TRX-NULL-2']);

        $this->assertSame(
            2,
            Order::query()->where('store_id', $store->id)->whereNull('idempotency_key')->count(),
        );
    }

    public function test_duplicate_store_key_is_rejected_on_orders(): void
    {
        [, $store] = $this->createOwnerWithStore();

        Order::factory()->for($store, 'store')->create([
            'order_number' => 'TRX-DUP-A',
            'idempotency_key' => 'dup-key',
        ]);

        $this->expectException(QueryException::class);

        Order::factory()->for($store, 'store')->create([
            'order_number' => 'TRX-DUP-B',
            'idempotency_key' => 'dup-key',
        ]);
    }

    public function test_duplicate_store_key_is_rejected_on_payments(): void
    {
        [, $store] = $this->createOwnerWithStore();
        $order = Order::factory()->for($store, 'store')->create();

        Payment::factory()->for($store, 'store')->for($order, 'order')->create([
            'idempotency_key' => 'dup-pay-key',
        ]);

        $this->expectException(QueryException::class);

        Payment::factory()->for($store, 'store')->for($order, 'order')->create([
            'idempotency_key' => 'dup-pay-key',
        ]);
    }

    private function assertUniqueIndexSpansStoreAndKey(string $table, string $name): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertNotNull($index, "Index {$name} is missing on {$table}.");
        $this->assertTrue($index['unique'], "Index {$name} must be unique.");
        $this->assertSame(['store_id', 'idempotency_key'], $index['columns']);
    }
}
