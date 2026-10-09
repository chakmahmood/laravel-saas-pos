<?php

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Order line. Catalog data is snapshotted at transaction time so later catalog
 * edits never change history.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'item_id',
        'item_name',
        'item_sku',
        'item_type',
        'unit',
        'quantity',
        'unit_price',
        'line_subtotal',
        'discount_amount',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'integer',
            'line_subtotal' => 'integer',
            'discount_amount' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
