<?php

namespace App\Http\Requests\Item;

use App\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    /**
     * Owner and admin may update items. Cashier may not.
     *
     * The item is resolved through the current store relation so that an item
     * of another tenant is treated exactly like a missing item (404).
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $item = $store->items()->findOrFail($this->route('item'));

        return (bool) $this->user()?->can('update', $item);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();
        $itemId = $this->route('item');

        $name = $this->isMethod('PUT')
            ? ['required', 'string', 'max:150']
            : ['sometimes', 'required', 'string', 'max:150'];

        $type = $this->isMethod('PUT')
            ? ['required', Rule::enum(ItemType::class)]
            : ['sometimes', 'required', Rule::enum(ItemType::class)];

        $sellingPrice = $this->isMethod('PUT')
            ? ['required', 'integer', 'min:0', 'max:999999999999999']
            : ['sometimes', 'required', 'integer', 'min:0', 'max:999999999999999'];

        return [
            'name' => $name,
            'type' => $type,
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('store_id', $storeId),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('items', 'sku')
                    ->where('store_id', $storeId)
                    ->ignore($itemId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('items', 'barcode')
                    ->where('store_id', $storeId)
                    ->ignore($itemId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'selling_price' => $sellingPrice,
            'unit' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalized attributes for the update. Only provided fields are returned,
     * so PATCH never resets untouched columns.
     *
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        $data = $this->validated();

        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        if (array_key_exists('category_id', $data)) {
            $data['category_id'] = $data['category_id'] !== null
                ? (int) $data['category_id']
                : null;
        }

        if (array_key_exists('selling_price', $data)) {
            $data['selling_price'] = (int) $data['selling_price'];
        }

        if (array_key_exists('cost_price', $data)) {
            $data['cost_price'] = $data['cost_price'] !== null
                ? (int) $data['cost_price']
                : null;
        }

        /*
         * `unit` is NOT NULL in the database with a default. An empty / null
         * value must not blank the column, so drop it and keep the current one.
         */
        if (array_key_exists('unit', $data) && ($data['unit'] === null || $data['unit'] === '')) {
            unset($data['unit']);
        }

        return $data;
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
