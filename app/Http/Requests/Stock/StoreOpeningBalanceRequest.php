<?php

namespace App\Http\Requests\Stock;

use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    /**
     * Only owner/admin may mutate stock.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', StockMovement::class);
    }

    /**
     * `stock_location_id` and `item_id` are validated as integers only; the
     * service resolves them through the current store relations so a foreign id
     * yields 404 instead of leaking existence.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_location_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999.999'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
