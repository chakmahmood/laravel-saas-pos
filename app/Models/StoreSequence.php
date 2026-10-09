<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent per-store counter used to build race-safe document numbers.
 *
 * The counter must be advanced while the store row is locked (see
 * OrderNumberService and OrderService).
 */
class StoreSequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'sequence_key',
        'last_value',
    ];

    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
