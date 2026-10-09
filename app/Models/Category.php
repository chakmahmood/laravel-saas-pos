<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Universal POS catalog category.
 *
 * A category always belongs to exactly one store. Names are unique per store,
 * never globally. The `items()` relation will be added once the catalog items
 * table exists (Phase 1b); deletion of an in-use category must then be
 * blocked instead of cascading item deletion.
 */
class Category extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     *
     * `store_id` is included for factory usage only. Controllers always set
     * ownership through the current store relation and never from request
     * input.
     */
    protected $fillable = [
        'store_id',
        'name',
        'description',
        'is_active',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Store that owns this category.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Catalog items that reference this category.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
