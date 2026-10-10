<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manually recorded payment. No gateway integration in this phase.
 *
 * Payments are never deleted; they are voided so the audit trail remains.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'order_id',
        'cash_session_id',
        'payment_method',
        'amount',
        'status',
        'reference_number',
        'idempotency_key',
        'request_fingerprint',
        'notes',
        'paid_at',
        'recorded_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Cashier shift this payment was attributed to (cash payments only).
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
