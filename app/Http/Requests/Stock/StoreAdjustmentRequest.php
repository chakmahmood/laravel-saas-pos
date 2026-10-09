<?php

namespace App\Http\Requests\Stock;

use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdjustmentRequest extends FormRequest
{
    /**
     * Only owner/admin may mutate stock.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', StockMovement::class);
    }

    /**
     * The client sends the physically counted quantity, not a new balance; the
     * delta is computed server-side against the locked current on-hand.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_location_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['required', 'integer', 'min:1'],
            'counted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999.999'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
