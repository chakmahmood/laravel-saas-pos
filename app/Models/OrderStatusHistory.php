<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for an order status change.
 */
class OrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * Rows are immutable; only created_at is stored.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_fulfillment_status',
        'to_fulfillment_status',
        'from_payment_status',
        'to_payment_status',
        'changed_by',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_fulfillment_status' => FulfillmentStatus::class,
            'to_fulfillment_status' => FulfillmentStatus::class,
            'from_payment_status' => PaymentStatus::class,
            'to_payment_status' => PaymentStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
