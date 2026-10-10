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
        private readonly StockLedgerService $stock,
    ) {}

    /**
     * Create an order with its items atomically.
     *
     * Idempotency: when the client supplies an `idempotency_key`, a replay with
     * the same key and the same payload returns the original order instead of
     * creating a second one. The lookup happens after the store row lock, so
     * concurrent retries are serialized and race-safe. Reusing a key with a
     * different payload is a 409 (`idempotency_conflict`).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws OrderConflictException
     */
    public function create(Store $store, User $cashier, array $data): Order
    {
        $idempotencyKey = $this->idempotencyKey($data);
        $fingerprint = $idempotencyKey !== null
            ? $this->orderFingerprint($data)
            : null;

        return DB::transaction(function () use ($store, $cashier, $data, $idempotencyKey, $fingerprint) {
            $lockedStore = Store::query()
                ->whereKey($store->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = Order::query()
                    ->where('store_id', $lockedStore->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertSameRequest(
                        $existing->request_fingerprint,
                        $fingerprint,
                    );

                    return $existing;
                }
            }

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
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
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

            /*
             * Reserve stock for stock-tracked items inside the same
             * transaction. No-op for orders without tracked items, so
             * non-inventory orders are unaffected. An insufficient-stock or
             * unsupported-inventory error aborts the whole order.
             */
            $this->stock->reserveForOrder($lockedStore, $order, $cashier);

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

            /*
             * Inventory reacts to the *new* status, inside the same
             * transaction:
             * - completed: commit the reservation (reduce on-hand).
             * - cancelled before commit: release the reservation.
             * Both are no-ops when the order never touched inventory, and both
             * are idempotent at the ledger level.
             */
            if ($to === FulfillmentStatus::COMPLETED) {
                $this->stock->commitForOrder($locked, $actor);
            } elseif ($to === FulfillmentStatus::CANCELLED) {
                $this->stock->releaseForOrder($locked, $actor);
            }

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

    /**
     * @param  array<string, mixed>  $data
     */
    private function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @throws OrderConflictException when the same key was used for a different payload
     */
    private function assertSameRequest(?string $stored, ?string $incoming): void
    {
        if ($stored !== $incoming) {
            throw new OrderConflictException(
                'Idempotency key ini sudah dipakai untuk permintaan order yang berbeda.',
                'idempotency_conflict',
            );
        }
    }

    /**
     * Deterministic fingerprint of the client payload. Items are canonicalized
     * (quantity normalized to 3 decimals, order-independent) so a retry that
     * only reorders lines still matches the original request.
     *
     * @param  array<string, mixed>  $data
     */
    private function orderFingerprint(array $data): string
    {
        $items = collect($data['items'])
            ->map(fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => $this->canonicalQuantity($line['quantity']),
                'discount_amount' => (int) ($line['discount_amount'] ?? 0),
            ])
            ->sortBy(fn (array $line): string => $line['item_id'].'|'.$line['quantity'].'|'.$line['discount_amount'])
            ->values()
            ->all();

        return hash('sha256', (string) json_encode([
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'items' => $items,
            'tax_amount' => (int) ($data['tax_amount'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ]));
    }

    private function canonicalQuantity(mixed $quantity): string
    {
        return number_format((float) $quantity, 3, '.', '');
    }
}
