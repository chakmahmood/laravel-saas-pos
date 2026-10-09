<?php

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Universal POS catalog item.
 *
 * One table represents every sellable thing (product, service, menu, package).
 * `store_id` is always derived from the current store, never from request
 * input. `category_id` is optional and must belong to the same store.
 */
class Item extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     *
     * `store_id` is included for factory usage only. The application always
     * sets ownership through the current store relation.
     */
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'type',
        'sku',
        'barcode',
        'description',
        'cost_price',
        'selling_price',
        'unit',
        'is_active',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'cost_price' => 'integer',
            'selling_price' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Store that owns this item.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Optional category of this item.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
