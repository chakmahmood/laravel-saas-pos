<?php

namespace App\Http\Requests\Stock;

use App\Enums\StockLocationType;
use App\Models\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockLocationRequest extends FormRequest
{
    /**
     * Owner and admin may create stock locations. Cashier may not.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', StockLocation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('stock_locations', 'name')->where('store_id', $storeId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('stock_locations', 'code')->where('store_id', $storeId),
            ],
            'type' => ['required', Rule::enum(StockLocationType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalized attributes for creation.
     *
     * `store_id`, `is_default` and `default_guard` are never taken from input:
     * ownership comes from the current store and the default flag is managed by
     * the server-side provisioning/service layer.
     *
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'code' => $this->filled('code') ? $this->string('code')->toString() : null,
            'type' => $this->input('type'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
