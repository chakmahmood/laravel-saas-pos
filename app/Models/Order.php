<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order header. Money fields are integer rupiah computed server-side.
 *
 * Payment and fulfillment statuses are independent: an order can be paid
 * before the work is finished (laundry) or finished before payment.
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'order_number',
        'customer_id',
        'cashier_id',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'payment_status',
        'fulfillment_status',
        'notes',
        'placed_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Include archived customers so order history stays readable.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Sum of payments that still count toward the order (not voided).
     */
    public function activePaidAmount(): int
    {
        return (int) $this->payments()
            ->where('status', PaymentRecordStatus::COMPLETED->value)
            ->sum('amount');
    }
}
