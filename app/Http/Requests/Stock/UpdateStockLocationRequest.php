<?php

namespace App\Http\Requests\Stock;

use App\Enums\StockLocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockLocationRequest extends FormRequest
{
    /**
     * Owner and admin may update stock locations. Cashier may not.
     *
     * The location is resolved through the current store relation so that a
     * location of another tenant is treated exactly like a missing one (404).
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $location = $store->stockLocations()->findOrFail($this->route('stockLocation'));

        return (bool) $this->user()?->can('update', $location);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();
        $locationId = $this->route('stockLocation');

        $name = $this->isMethod('PUT')
            ? ['required', 'string', 'max:100']
            : ['sometimes', 'required', 'string', 'max:100'];

        $type = $this->isMethod('PUT')
            ? ['required', Rule::enum(StockLocationType::class)]
            : ['sometimes', 'required', Rule::enum(StockLocationType::class)];

        return [
            'name' => array_merge($name, [
                Rule::unique('stock_locations', 'name')
                    ->where('store_id', $storeId)
                    ->ignore($locationId),
            ]),
            'code' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('stock_locations', 'code')
                    ->where('store_id', $storeId)
                    ->ignore($locationId),
            ],
            'type' => $type,
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only client-manageable fields. `is_default`, `default_guard` and
     * `store_id` are never taken from input.
     *
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        $data = $this->validated();

        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        if (array_key_exists('code', $data) && $data['code'] === null) {
            $data['code'] = null;
        }

        return $data;
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
