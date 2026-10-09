<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual cash movement inside a shift.
 *
 * Movements are append-only: there is no API to edit or delete them. A wrong
 * entry is corrected with an opposite movement, preserving the audit trail.
 */
class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'cash_session_id',
        'user_id',
        'type',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
