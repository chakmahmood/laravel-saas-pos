<?php

namespace App\Services;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderConflictException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for orders.
 *
 * All order creation and status changes go through this service so that:
 *
 * 1. The store row is locked, serializing order creation per store and making
 *    order numbers and totals race-safe.
 * 2. Catalog prices and item data are read from the database; client prices,
 *    totals and store ownership are never trusted.
 * 3. Order, order items and the initial status history are written in a single
 *    transaction. Any invalid line aborts the whole order.
 *
 * Concurrency note: row locking is real on MySQL 8 InnoDB. SQLite (tests) does
 * not implement FOR UPDATE, so SQLite verifies the protocol, not true
 * concurrent isolation.
 */
class OrderService
{
    /**
     * Hard ceiling for any single money value and for the order total.
     *
     * 9e15 is below 2^53 (9.007e15), so float multiplications used for
     * fractional quantities stay exact, and far below PHP_INT_MAX / unsigned
     * BIGINT. Values above this are rejected instead of overflowing.
     */
    private const MAX_MONEY = 9_000_000_000_000_000;

    public function __construct(
        private readonly OrderNumberService $numbers,
    ) {}

    /**
     * Create an order with its items atomically.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Store $store, User $cashier, array $data): Order
    {
        return DB::transaction(function () use ($store, $cashier, $data) {
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $customer = $this->resolveCustomer(
                $lockedStore,
                $data['customer_id'] ?? null,
            );

            $lines = $this->buildLines($lockedStore, $data['items']);

            $subtotal = array_sum(array_column($lines, 'line_subtotal'));
            $discount = array_sum(array_column($lines, 'discount_amount'));
            $tax = (int) ($data['tax_amount'] ?? 0);

            $this->assertMoneyWithinBounds($subtotal, 'subtotal');
            $this->assertMoneyWithinBounds($discount, 'discount_amount');
            $this->assertMoneyWithinBounds($tax, 'tax_amount');

            $total = $subtotal - $discount + $tax;

            if ($total < 0) {
                throw ValidationException::withMessages([
                    'items' => 'Total transaksi tidak boleh negatif.',
                ]);
            }

            $this->assertMoneyWithinBounds($total, 'total_amount');

            $order = $lockedStore->orders()->create([
                'order_number' => $this->numbers->next($lockedStore),
                'customer_id' => $customer?->id,
                'cashier_id' => $cashier->id,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'paid_amount' => 0,
                'payment_status' => $total <= 0
                    ? PaymentStatus::PAID
                    : PaymentStatus::UNPAID,
                'fulfillment_status' => FulfillmentStatus::PENDING,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            $order->statusHistories()->create([
                'from_fulfillment_status' => null,
                'to_fulfillment_status' => FulfillmentStatus::PENDING->value,
                'from_payment_status' => null,
                'to_payment_status' => $order->payment_status->value,
                'changed_by' => $cashier->id,
                'reason' => 'Order dibuat',
                'created_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * Change the fulfillment status of an order following the allowed
     * transitions. Cancelling an order that still has active payments is
     * rejected as a conflict.
     */
    public function changeFulfillment(
        Order $order,
        FulfillmentStatus $to,
        ?string $reason,
        User $actor,
    ): Order {
        return DB::transaction(function () use ($order, $to, $reason, $actor) {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->fulfillment_status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'fulfillment_status' => "Transisi dari {$from->value} ke {$to->value} tidak diizinkan.",
                ]);
            }

            if ($to === FulfillmentStatus::CANCELLED && $locked->activePaidAmount() > 0) {
                throw new OrderConflictException(
                    'Order yang masih memiliki pembayaran aktif tidak dapat dibatalkan. Void pembayaran terlebih dahulu.',
                );
            }

            $locked->fulfillment_status = $to;

            if ($to === FulfillmentStatus::COMPLETED) {
                $locked->completed_at = now();
            }

            if ($to === FulfillmentStatus::CANCELLED) {
                $locked->cancelled_at = now();
                $locked->cancel_reason = $reason;
            }

            $locked->save();

            $locked->statusHistories()->create([
                'from_fulfillment_status' => $from->value,
                'to_fulfillment_status' => $to->value,
                'from_payment_status' => $locked->payment_status->value,
                'to_payment_status' => $locked->payment_status->value,
                'changed_by' => $actor->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $requested
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(Store $store, array $requested): array
    {
        $itemIds = collect($requested)
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $items = $store->items()
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($requested as $index => $line) {
            $item = $items->get((int) $line['item_id']);

            if ($item === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => 'Item tidak ditemukan pada toko ini.',
                ]);
            }

            if (! $item->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_id" => 'Item tidak aktif dan tidak dapat dijual.',
                ]);
            }

            $quantity = (float) $line['quantity'];

            // Backend price only; client-supplied unit price is ignored.
            $unitPrice = (int) $item->selling_price;
            $lineSubtotal = $this->lineSubtotal($unitPrice, $quantity, $index);

            $discount = (int) ($line['discount_amount'] ?? 0);

            if ($discount > $lineSubtotal) {
                throw ValidationException::withMessages([
                    "items.{$index}.discount_amount" => 'Diskon tidak boleh melebihi subtotal baris.',
                ]);
            }

            $lines[] = [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'item_sku' => $item->sku,
                'item_type' => $item->type->value,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $lineSubtotal,
                'discount_amount' => $discount,
                'line_total' => $lineSubtotal - $discount,
            ];
        }

        return $lines;
    }

    private function resolveCustomer(Store $store, ?int $customerId): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        // Default query excludes archived (soft deleted) customers.
        $customer = $store->customers()->find($customerId);

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'Pelanggan tidak ditemukan pada toko ini.',
            ]);
        }

        return $customer;
    }

    /**
     * Compute a line subtotal with overflow protection.
     *
     * Whole quantities use exact integer multiplication; fractional quantities
     * (e.g. laundry weight) use float multiplication that stays exact because
     * the result is capped at MAX_MONEY, below 2^53.
     */
    private function lineSubtotal(int $unitPrice, float $quantity, int $index): int
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                "items.{$index}.quantity" => 'Kuantitas harus lebih besar dari nol.',
            ]);
        }

        if (floor($quantity) === $quantity) {
            $whole = (int) $quantity;

            if ($unitPrice !== 0 && $whole > intdiv(self::MAX_MONEY, $unitPrice)) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Nilai baris melebihi batas maksimum yang diizinkan.',
                ]);
            }

            $lineSubtotal = $unitPrice * $whole;
        } else {
            $product = $unitPrice * $quantity;

            if ($product > self::MAX_MONEY) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Nilai baris melebihi batas maksimum yang diizinkan.',
                ]);
            }

            $lineSubtotal = (int) round($product);
        }

        return $lineSubtotal;
    }

    private function assertMoneyWithinBounds(int $value, string $field): void
    {
        if ($value > self::MAX_MONEY) {
            throw ValidationException::withMessages([
                $field => 'Nilai uang melebihi batas maksimum yang diizinkan.',
            ]);
        }
    }
}
