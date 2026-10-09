<?php

namespace App\Http\Requests\Stock;

use App\Models\StockMovement;
use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    /**
     * Only owner/admin may mutate stock.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', StockMovement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_location_id' => ['required', 'integer', 'min:1'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999.999'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
