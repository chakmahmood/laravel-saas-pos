<?php

namespace App\Models;

use App\Enums\StockLocationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical place where stock lives (warehouse, outlet, ...).
 *
 * Every store has exactly one default location, guaranteed portably by the
 * unique `default_guard` column (see the table migration). The guard is kept
 * by the provisioning/service layer, never from request input:
 *
 *   default_guard = "<store_id>"  when is_default = true
 *   default_guard = NULL          otherwise
 */
class StockLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'type',
        'is_default',
        'is_active',
        'default_guard',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockLocationType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
