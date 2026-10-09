<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\OrderConflictException;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Quantity;
use App\Support\StockMutationResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inventory ledger write path for orders.
 *
 * Responsibilities:
 * - Reserve stock when an order is created (physical stock is NOT reduced).
 * - Commit stock when an order becomes `completed` (reduce on-hand, release the
 *   reservation that was held).
 * - Release the reservation when an order is cancelled before commit.
 *
 * Guarantees:
 * - Every balance change and its ledger movement happen in one transaction.
 * - Movement quantities are always positive; the type decides the direction.
 * - Idempotency is enforced by an order-level guard (`orders.stock_committed_at`
 *   / presence of a release movement) plus a deterministic per order-item
 *   `idempotency_key` protected by a unique index (a second layer, not the only
 *   one).
 * - Tenant consistency (item, location, balance, order in the same store) is
 *   validated here; foreign keys alone are never trusted.
 * - Availability is checked with a conditional atomic UPDATE and the affected
 *   row count is verified, so concurrent transactions cannot oversell.
 *
 * Lock ordering follows the canonical order used across the project:
 *
 *   store -> order -> stock_balances (location_id ASC, item_id ASC)
 *
 * Concurrency note: `lockForUpdate()` is real on MySQL 8 InnoDB. SQLite (tests)
 * does not implement FOR UPDATE, so the SQLite suite verifies the protocol and
 * the transaction/rollback behaviour, not true parallel isolation.
 */
class StockLedgerService
{
    /**
     * Reserve stock for a newly created order.
     *
     * Called inside OrderService::create()'s transaction, after the order and
     * its items exist. No-op for orders without stock-tracked items.
     *
     * @throws OrderConflictException
     */
    public function reserveForOrder(Store $store, Order $order, User $actor): void
    {
        DB::transaction(function () use ($store, $order, $actor) {
            if ((int) $order->store_id !== (int) $store->getKey()) {
                throw new OrderConflictException(
                    'Order tidak sesuai dengan toko aktif.',
                    'stock_tenant_mismatch',
                );
            }

            $lines = $this->trackedLines($store, $order);

            if ($lines->isEmpty()) {
                return;
            }

            if (! $store->business_type->usesInventory()) {
                throw new OrderConflictException(
                    'Toko ini tidak mendukung pengelolaan stok, tetapi pesanan berisi item yang dilacak stok.',
                    'inventory_not_supported',
                );
            }

            $location = $this->activeDefaultLocation($store);

            $requested = $this->aggregateByItem($lines);

            ksort($requested);

            foreach ($requested as $itemId => $millis) {
                $this->reserveBalance($store->getKey(), $location->getKey(), $itemId, $millis);
            }

            $order->forceFill(['stock_location_id' => $location->getKey()])->save();

            foreach ($lines as $line) {
                $this->recordMovement([
                    'store_id' => $store->getKey(),
                    'stock_location_id' => $location->getKey(),
                    'item_id' => $line->item_id,
                    'type' => StockMovementType::RESERVATION,
                    'quantity' => $line->quantity,
                    'order_id' => $order->getKey(),
                    'order_item_id' => $line->getKey(),
                    'idempotency_key' => $this->orderItemKey($order, $line->getKey(), 'reserve'),
                    'created_by' => $actor->getKey(),
                    'note' => 'Reservasi untuk order '.$order->order_number,
                ]);
            }
        });
    }

    /**
     * Commit stock for an order. Idempotent: an already committed order is a
     * no-op. No-op for orders without reservations (non-inventory orders).
     *
     * @throws OrderConflictException
     */
    public function commitForOrder(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->hasCommittedStock()) {
                return;
            }

            $reservations = $this->reservationsFor($locked);

            if ($reservations->isEmpty()) {
                return;
            }

            $this->settleReservations($locked, $reservations, $actor, commit: true);

            $locked->forceFill(['stock_committed_at' => now()])->save();
        });
    }

    /**
     * Release the reservation for an order cancelled before commit. Idempotent:
     * an already released order is a no-op.
     *
     * @throws OrderConflictException
     */
    public function releaseForOrder(Order $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $reservations = $this->reservationsFor($locked);

            if ($reservations->isEmpty()) {
                return;
            }

            $alreadyReleased = StockMovement::query()
                ->where('store_id', $locked->store_id)
                ->where('order_id', $locked->getKey())
                ->where('type', StockMovementType::RESERVATION_RELEASE->value)
                ->exists();

            if ($alreadyReleased) {
                return;
            }

            $this->settleReservations($locked, $reservations, $actor, commit: false);
        });
    }

    /**
     * Record opening stock for an item/location pair that has no balance and no
     * ledger history yet. Establishes the starting on-hand quantity exactly
     * once; it can never be used to overwrite an existing balance.
     *
     * Idempotent per (store, idempotency_key): an identical retry returns the
     * existing movement without touching stock again, while reusing the key
     * with a different payload is rejected as a conflict.
     *
     * @throws OrderConflictException
     */
    public function recordOpening(
        Store $store,
        int $locationId,
        int $itemId,
        int|float|string $quantity,
        string $idempotencyKey,
        User $actor,
        ?string $note = null,
    ): StockMutationResult {
        $millis = $this->positiveMillis($quantity);
        $fingerprint = $this->fingerprint('opening', $store->getKey(), $locationId, $itemId, $millis, $note);

        return DB::transaction(function () use ($store, $locationId, $itemId, $millis, $idempotencyKey, $actor, $note, $fingerprint) {
            $this->lockStore($store);

            $existing = $this->movementByKey($store->getKey(), $idempotencyKey);

            if ($existing !== null) {
                return $this->idempotentResult($store->getKey(), $existing, $fingerprint, $locationId, $itemId);
            }

            $location = $this->resolveLocation($store, $locationId);
            $item = $this->resolveTrackedItem($store, $itemId);

            $hasHistory = StockBalance::query()
                ->where('store_id', $store->getKey())
                ->where('stock_location_id', $location->getKey())
                ->where('item_id', $item->getKey())
                ->exists()
                || StockMovement::query()
                    ->where('store_id', $store->getKey())
                    ->where('stock_location_id', $location->getKey())
                    ->where('item_id', $item->getKey())
                    ->exists();

            if ($hasHistory) {
                throw new OrderConflictException(
                    'Saldo awal tidak dapat dicatat karena kombinasi item/lokasi ini sudah memiliki saldo atau riwayat stok.',
                    'opening_stock_conflict',
                );
            }

            $balance = StockBalance::query()->create([
                'store_id' => $store->getKey(),
                'stock_location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'quantity_on_hand' => Quantity::fromMillis($millis),
                'quantity_reserved' => 0,
            ]);

            $movement = $this->recordMovement([
                'store_id' => $store->getKey(),
                'stock_location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'type' => StockMovementType::OPENING,
                'quantity' => Quantity::fromMillis($millis),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(),
                'note' => $note ?? 'Saldo awal',
            ]);

            return new StockMutationResult($balance->refresh(), $movement);
        });
    }

    /**
     * Record a manual stock receipt (goods in): increases on-hand only and
     * never touches the reserved quantity.
     *
     * @throws OrderConflictException
     */
    public function recordReceipt(
        Store $store,
        int $locationId,
        int $itemId,
        int|float|string $quantity,
        string $idempotencyKey,
        User $actor,
        ?string $reference = null,
    ): StockMutationResult {
        $millis = $this->positiveMillis($quantity);
        $fingerprint = $this->fingerprint('receipt', $store->getKey(), $locationId, $itemId, $millis, $reference);

        return DB::transaction(function () use ($store, $locationId, $itemId, $millis, $idempotencyKey, $actor, $reference, $fingerprint) {
            $this->lockStore($store);

            $existing = $this->movementByKey($store->getKey(), $idempotencyKey);

            if ($existing !== null) {
                return $this->idempotentResult($store->getKey(), $existing, $fingerprint, $locationId, $itemId);
            }

            $location = $this->resolveLocation($store, $locationId);
            $item = $this->resolveTrackedItem($store, $itemId);

            $balance = $this->lockOrCreateBalance($store->getKey(), $location->getKey(), $item->getKey());
            $newOnHand = Quantity::toMillis($balance->quantity_on_hand) + $millis;

            $this->setOnHand($balance, $newOnHand);

            $movement = $this->recordMovement([
                'store_id' => $store->getKey(),
                'stock_location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'type' => StockMovementType::PURCHASE_IN,
                'quantity' => Quantity::fromMillis($millis),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(),
                'note' => $reference,
            ]);

            return new StockMutationResult($balance->refresh(), $movement);
        });
    }

    /**
     * Correct stock against a physical count.
     *
     * The client sends the counted quantity (not a new balance), so the delta
     * is always computed from the locked current on-hand. A count below the
     * reserved quantity is rejected; the reservation is never modified here.
     * A zero delta is a documented no-op with no movement written.
     *
     * @throws OrderConflictException
     */
    public function recordAdjustment(
        Store $store,
        int $locationId,
        int $itemId,
        int|float|string $countedQuantity,
        string $idempotencyKey,
        User $actor,
        ?string $reason = null,
    ): StockMutationResult {
        $counted = $this->quantityMillis($countedQuantity);

        if ($counted < 0) {
            throw new OrderConflictException('Kuantitas hasil hitung tidak valid.', 'stock_invalid_quantity');
        }

        $fingerprint = $this->fingerprint('adjustment', $store->getKey(), $locationId, $itemId, $counted, $reason);

        return DB::transaction(function () use ($store, $locationId, $itemId, $counted, $idempotencyKey, $actor, $reason, $fingerprint) {
            $this->lockStore($store);

            $existing = $this->movementByKey($store->getKey(), $idempotencyKey);

            if ($existing !== null) {
                return $this->idempotentResult($store->getKey(), $existing, $fingerprint, $locationId, $itemId);
            }

            $location = $this->resolveLocation($store, $locationId);
            $item = $this->resolveTrackedItem($store, $itemId);

            $balance = $this->lockOrCreateBalance($store->getKey(), $location->getKey(), $item->getKey());
            $current = Quantity::toMillis($balance->quantity_on_hand);
            $reserved = Quantity::toMillis($balance->quantity_reserved);
            $delta = $counted - $current;

            if ($delta === 0) {
                return new StockMutationResult($balance->refresh(), null, idempotent: false, noOp: true);
            }

            if ($counted < $reserved) {
                throw new OrderConflictException(
                    'Hasil hitung fisik lebih rendah dari jumlah yang direservasi order; selesaikan atau batalkan order terlebih dahulu.',
                    'adjustment_below_reserved',
                );
            }

            $this->setOnHand($balance, $counted);

            $movement = $this->recordMovement([
                'store_id' => $store->getKey(),
                'stock_location_id' => $location->getKey(),
                'item_id' => $item->getKey(),
                'type' => $delta > 0 ? StockMovementType::ADJUSTMENT_IN : StockMovementType::ADJUSTMENT_OUT,
                'quantity' => Quantity::fromMillis(abs($delta)),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_by' => $actor->getKey(),
                'note' => $reason,
            ]);

            return new StockMutationResult($balance->refresh(), $movement);
        });
    }

    /**
     * Order items whose catalog item tracks stock, validated to belong to the
     * store. An order line referencing an item that is NOT in the store is a
     * tenant violation and is rejected.
     *
     * @return Collection<int, OrderItem>
     */
    private function trackedLines(Store $store, Order $order): Collection
    {
        $lines = $order->items()->get();

        if ($lines->isEmpty()) {
            return collect();
        }

        $itemIds = $lines->pluck('item_id')->filter()->unique()->values();

        $storeItems = $store->items()
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        return $lines
            ->filter(function (OrderItem $line) use ($storeItems): bool {
                if ($line->item_id === null) {
                    return false;
                }

                $item = $storeItems->get($line->item_id);

                if ($item === null) {
                    throw new OrderConflictException(
                        'Item pesanan tidak ditemukan pada toko ini.',
                        'stock_tenant_mismatch',
                    );
                }

                return (bool) $item->tracks_stock;
            })
            ->values();
    }

    private function activeDefaultLocation(Store $store): StockLocation
    {
        $location = $store->stockLocations()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($location === null) {
            throw new OrderConflictException(
                'Lokasi stok default belum tersedia untuk toko ini.',
                'stock_location_unavailable',
            );
        }

        return $location;
    }

    /**
     * All reservation movements for the order, ordered so balances are locked
     * deterministically.
     *
     * @return Collection<int, StockMovement>
     */
    private function reservationsFor(Order $order): Collection
    {
        return StockMovement::query()
            ->where('store_id', $order->store_id)
            ->where('order_id', $order->getKey())
            ->where('type', StockMovementType::RESERVATION->value)
            ->orderBy('stock_location_id')
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, OrderItem>  $lines
     * @return array<int, int> item id => requested millis
     */
    private function aggregateByItem(Collection $lines): array
    {
        $requested = [];

        foreach ($lines as $line) {
            $millis = Quantity::toMillis($line->quantity);
            $requested[$line->item_id] = ($requested[$line->item_id] ?? 0) + $millis;
        }

        return $requested;
    }

    private function reserveBalance(int $storeId, int $locationId, int $itemId, int $millis): void
    {
        if ($millis <= 0) {
            throw new OrderConflictException('Kuantitas reservasi tidak valid.', 'stock_invalid_quantity');
        }

        $balance = $this->lockBalance($storeId, $locationId, $itemId);

        if ($balance === null) {
            throw new OrderConflictException('Stok tidak mencukupi untuk salah satu item.', 'insufficient_stock');
        }

        $quantity = Quantity::fromMillis($millis);

        /*
         * The quantity literal is produced from an integer (millis) and only
         * ever contains digits and a dot, so it is safe to inline. Inlining a
         * numeric literal (instead of binding a string) also keeps the
         * comparison numeric on both MySQL and SQLite, where a text-bound
         * parameter would not compare correctly against an expression.
         */
        $affected = DB::table('stock_balances')
            ->where('id', $balance->getKey())
            ->whereRaw("(quantity_on_hand - quantity_reserved) >= {$quantity}")
            ->update([
                'quantity_reserved' => DB::raw('quantity_reserved + '.$quantity),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new OrderConflictException('Stok tidak mencukupi untuk salah satu item.', 'insufficient_stock');
        }
    }

    /**
     * Apply the reservation settlement to balances and append the matching
     * ledger movements.
     *
     * @param  Collection<int, StockMovement>  $reservations
     */
    private function settleReservations(Order $order, Collection $reservations, User $actor, bool $commit): void
    {
        $aggregated = [];

        foreach ($reservations as $reservation) {
            $key = $reservation->stock_location_id.':'.$reservation->item_id;

            $aggregated[$key] ??= [
                'location_id' => (int) $reservation->stock_location_id,
                'item_id' => (int) $reservation->item_id,
                'millis' => 0,
            ];

            $aggregated[$key]['millis'] += Quantity::toMillis($reservation->quantity);
        }

        ksort($aggregated);

        foreach ($aggregated as $entry) {
            if ($commit) {
                $this->commitBalance((int) $order->store_id, $entry['location_id'], $entry['item_id'], $entry['millis']);
            } else {
                $this->releaseBalance((int) $order->store_id, $entry['location_id'], $entry['item_id'], $entry['millis']);
            }
        }

        foreach ($reservations as $reservation) {
            $base = [
                'store_id' => $order->store_id,
                'stock_location_id' => $reservation->stock_location_id,
                'item_id' => $reservation->item_id,
                'order_id' => $order->getKey(),
                'order_item_id' => $reservation->order_item_id,
                'created_by' => $actor->getKey(),
            ];

            if ($commit) {
                $this->recordMovement($base + [
                    'type' => StockMovementType::SALE_OUT,
                    'quantity' => $reservation->quantity,
                    'idempotency_key' => $this->orderItemKey($order, $reservation->order_item_id, 'sale'),
                    'note' => 'Penjualan order '.$order->order_number,
                ]);

                $this->recordMovement($base + [
                    'type' => StockMovementType::RESERVATION_RELEASE,
                    'quantity' => $reservation->quantity,
                    'idempotency_key' => $this->orderItemKey($order, $reservation->order_item_id, 'commit_release'),
                    'note' => 'Pelepasan reservasi saat komit '.$order->order_number,
                ]);
            } else {
                $this->recordMovement($base + [
                    'type' => StockMovementType::RESERVATION_RELEASE,
                    'quantity' => $reservation->quantity,
                    'idempotency_key' => $this->orderItemKey($order, $reservation->order_item_id, 'cancel_release'),
                    'note' => 'Pelepasan reservasi saat pembatalan '.$order->order_number,
                ]);
            }
        }
    }

    private function commitBalance(int $storeId, int $locationId, int $itemId, int $millis): void
    {
        if ($millis <= 0) {
            throw new OrderConflictException('Saldo stok tidak konsisten saat komit.', 'stock_inconsistent');
        }

        $balance = $this->lockBalance($storeId, $locationId, $itemId);

        if ($balance === null) {
            throw new OrderConflictException('Saldo stok untuk komit tidak ditemukan.', 'stock_inconsistent');
        }

        $quantity = Quantity::fromMillis($millis);

        $affected = DB::table('stock_balances')
            ->where('id', $balance->getKey())
            ->whereRaw("quantity_reserved >= {$quantity}")
            ->whereRaw("quantity_on_hand >= {$quantity}")
            ->update([
                'quantity_on_hand' => DB::raw('quantity_on_hand - '.$quantity),
                'quantity_reserved' => DB::raw('quantity_reserved - '.$quantity),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new OrderConflictException('Saldo stok tidak konsisten saat komit.', 'stock_inconsistent');
        }
    }

    private function releaseBalance(int $storeId, int $locationId, int $itemId, int $millis): void
    {
        if ($millis <= 0) {
            throw new OrderConflictException('Saldo stok tidak konsisten saat pelepasan.', 'stock_inconsistent');
        }

        $balance = $this->lockBalance($storeId, $locationId, $itemId);

        if ($balance === null) {
            throw new OrderConflictException('Saldo stok untuk pelepasan tidak ditemukan.', 'stock_inconsistent');
        }

        $quantity = Quantity::fromMillis($millis);

        $affected = DB::table('stock_balances')
            ->where('id', $balance->getKey())
            ->whereRaw("quantity_reserved >= {$quantity}")
            ->update([
                'quantity_reserved' => DB::raw('quantity_reserved - '.$quantity),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new OrderConflictException('Saldo stok tidak konsisten saat pelepasan.', 'stock_inconsistent');
        }
    }

    /**
     * Lock a balance row scoped to the store, enforcing tenant consistency.
     */
    private function lockBalance(int $storeId, int $locationId, int $itemId): ?StockBalance
    {
        return StockBalance::query()
            ->where('store_id', $storeId)
            ->where('stock_location_id', $locationId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws QueryException when the movement cannot be
     *                        appended (e.g. idempotency
     *                        collision); the surrounding
     *                        transaction rolls back.
     */
    private function recordMovement(array $attributes): StockMovement
    {
        return StockMovement::query()->create($attributes);
    }

    private function orderItemKey(Order $order, ?int $orderItemId, string $event): string
    {
        return 'order:'.$order->getKey().':item:'.$orderItemId.':'.$event;
    }

    /**
     * Serialize stock mutations per store, following the canonical lock order
     * (store -> balance). This also makes the idempotency lookup race-safe.
     */
    private function lockStore(Store $store): void
    {
        Store::query()
            ->whereKey($store->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function resolveLocation(Store $store, int $locationId): StockLocation
    {
        return $store->stockLocations()->findOrFail($locationId);
    }

    /**
     * @throws OrderConflictException when the item does not track stock
     */
    private function resolveTrackedItem(Store $store, int $itemId): Item
    {
        $item = $store->items()->findOrFail($itemId);

        if (! $item->tracks_stock) {
            throw new OrderConflictException(
                'Item ini tidak melacak stok.',
                'inventory_item_not_tracked',
            );
        }

        return $item;
    }

    private function lockOrCreateBalance(int $storeId, int $locationId, int $itemId): StockBalance
    {
        $balance = $this->lockBalance($storeId, $locationId, $itemId);

        if ($balance !== null) {
            return $balance;
        }

        try {
            return StockBalance::query()->create([
                'store_id' => $storeId,
                'stock_location_id' => $locationId,
                'item_id' => $itemId,
                'quantity_on_hand' => 0,
                'quantity_reserved' => 0,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $balance = $this->lockBalance($storeId, $locationId, $itemId);

            if ($balance === null) {
                throw $exception;
            }

            return $balance;
        }
    }

    /**
     * Set the physical on-hand quantity with a conditional atomic update. The
     * guard re-checks the previous value and forbids dropping on-hand below the
     * reserved quantity, so a lost update or a negative available can never
     * slip through even if the row lock were bypassed.
     *
     * @throws OrderConflictException
     */
    private function setOnHand(StockBalance $balance, int $newOnHandMillis): void
    {
        $currentLiteral = Quantity::fromMillis(Quantity::toMillis($balance->quantity_on_hand));
        $newLiteral = Quantity::fromMillis($newOnHandMillis);

        $affected = DB::table('stock_balances')
            ->where('id', $balance->getKey())
            ->whereRaw("quantity_on_hand = {$currentLiteral}")
            ->whereRaw("quantity_reserved <= {$newLiteral}")
            ->update([
                'quantity_on_hand' => DB::raw($newLiteral),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new OrderConflictException(
                'Saldo stok tidak konsisten saat memperbarui on-hand.',
                'stock_inconsistent',
            );
        }
    }

    private function movementByKey(int $storeId, string $idempotencyKey): ?StockMovement
    {
        return StockMovement::query()
            ->where('store_id', $storeId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * @throws OrderConflictException when the same key was used for a different payload
     */
    private function idempotentResult(
        int $storeId,
        StockMovement $existing,
        string $fingerprint,
        int $locationId,
        int $itemId,
    ): StockMutationResult {
        if ($existing->request_fingerprint !== $fingerprint) {
            throw new OrderConflictException(
                'Idempotency key ini sudah dipakai untuk permintaan yang berbeda.',
                'idempotency_conflict',
            );
        }

        $balance = StockBalance::query()
            ->where('store_id', $storeId)
            ->where('stock_location_id', $locationId)
            ->where('item_id', $itemId)
            ->first();

        if ($balance === null) {
            throw new OrderConflictException(
                'Saldo stok untuk permintaan idempotent tidak ditemukan.',
                'stock_inconsistent',
            );
        }

        return new StockMutationResult($balance, $existing, idempotent: true);
    }

    private function fingerprint(
        string $operation,
        int $storeId,
        int $locationId,
        int $itemId,
        int $millis,
        ?string $detail,
    ): string {
        return hash('sha256', implode('|', [
            $operation,
            $storeId,
            $locationId,
            $itemId,
            $millis,
            $detail ?? '',
        ]));
    }

    private function positiveMillis(int|float|string $quantity): int
    {
        $millis = $this->quantityMillis($quantity);

        if ($millis <= 0) {
            throw new OrderConflictException('Kuantitas harus lebih besar dari nol.', 'stock_invalid_quantity');
        }

        return $millis;
    }

    private function quantityMillis(int|float|string $quantity): int
    {
        try {
            return Quantity::toMillis($quantity);
        } catch (InvalidArgumentException) {
            throw new OrderConflictException('Kuantitas tidak valid.', 'stock_invalid_quantity');
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || (string) ($exception->errorInfo[0] ?? '') === '23000';
    }
}
