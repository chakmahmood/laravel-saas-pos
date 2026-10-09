<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Append-only inventory ledger row.
 *
 * Movements are never edited or deleted by business logic. A correction is a
 * new movement (typically `reversal`, pointing at the row it corrects via
 * `reversal_of_id`). The `quantity` is always positive; the direction is
 * determined by the movement `type`.
 *
 * The two hard ledger invariants are enforced here, at the model boundary, so
 * they hold on MySQL and SQLite alike (no portable DB CHECK exists for them):
 * quantity must be > 0, and rows are immutable/undeletable.
 */
class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'stock_location_id',
        'item_id',
        'type',
        'quantity',
        'unit_cost',
        'order_id',
        'order_item_id',
        'reversal_of_id',
        'idempotency_key',
        'request_fingerprint',
        'note',
        'created_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'unit_cost' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Quantity is always positive; the type decides the direction. Zero or
         * negative quantities are a programming error, not a valid movement.
         */
        static::saving(function (StockMovement $movement): void {
            if ((float) $movement->quantity <= 0) {
                throw new InvalidArgumentException(
                    'Stock movement quantity must be greater than zero.'
                );
            }
        });

        /*
         * The ledger is append-only. Corrections are new movements, never
         * edits or deletes of existing rows.
         */
        static::updating(function (): void {
            throw new LogicException('Stock movements are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Stock movements are append-only and cannot be deleted.');
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reversal_of_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
