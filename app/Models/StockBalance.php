<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operationally projected stock for one item at one location.
 *
 *   available = quantity_on_hand - quantity_reserved
 *
 * The row is a projection of the append-only ledger and is always updated
 * together with `stock_movements` inside one database transaction. It is not
 * the source of truth; the ledger is. Non-negativity and reservation bounds
 * are enforced by the service layer, not by a database CHECK constraint (see
 * the table migration for the portability reason).
 */
class StockBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'stock_location_id',
        'item_id',
        'quantity_on_hand',
        'quantity_reserved',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'quantity_reserved' => 'decimal:3',
        ];
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
}
