<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cashier shift (cash session).
 *
 * A shift belongs to one store and one cashier. Expected cash is computed from
 * opening cash, manual movements and valid cash payments:
 *
 *   expected = opening_cash + cash_in - cash_out + cash_sales
 *
 * The `open_guard` column is maintained by CashSessionService: non-null while
 * the shift is open, null once closed. Its unique index is the race guard.
 */
class CashSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'cashier_id',
        'status',
        'opening_cash',
        'opened_at',
        'closed_at',
        'expected_cash',
        'actual_cash',
        'difference',
        'opening_notes',
        'closing_notes',
        'open_guard',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_cash' => 'integer',
            'expected_cash' => 'integer',
            'actual_cash' => 'integer',
            'difference' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Payments that count toward drawer cash: cash method and still active.
     */
    public function cashPayments(): HasMany
    {
        return $this->hasMany(Payment::class)
            ->where('payment_method', PaymentMethod::CASH->value)
            ->where('status', PaymentRecordStatus::COMPLETED->value);
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::OPEN;
    }

    /**
     * Eager-load the three aggregates used to compute the cash summary without
     * N+1 queries. Aliases become `cash_in_total`, `cash_out_total` and
     * `cash_sales_total` attributes.
     */
    public function scopeWithSummary(Builder $query): Builder
    {
        return $query
            ->withSum(
                ['movements as cash_in_total' => fn (Builder $q) => $q->where('type', CashMovementType::CASH_IN->value)],
                'amount',
            )
            ->withSum(
                ['movements as cash_out_total' => fn (Builder $q) => $q->where('type', CashMovementType::CASH_OUT->value)],
                'amount',
            )
            ->withSum('cashPayments as cash_sales_total', 'amount');
    }
}
