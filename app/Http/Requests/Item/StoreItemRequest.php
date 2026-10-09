<?php

namespace App\Http\Requests\Item;

use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    /**
     * Owner and admin may create items. Cashier may not.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Item::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();

        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(ItemType::class)],

            /*
             * The category must belong to the current store. A foreign
             * tenant's category can never be referenced.
             */
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('store_id', $storeId),
            ],

            'sku' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('items', 'sku')->where('store_id', $storeId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('items', 'barcode')->where('store_id', $storeId),
            ],

            'description' => ['nullable', 'string', 'max:2000'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999999'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalized attributes for the catalog service.
     *
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        return [
            'category_id' => $this->filled('category_id')
                ? (int) $this->input('category_id')
                : null,
            'name' => $this->string('name')->toString(),
            'type' => $this->input('type'),
            'sku' => $this->input('sku'),
            'barcode' => $this->input('barcode'),
            'description' => $this->input('description'),
            'cost_price' => $this->filled('cost_price')
                ? (int) $this->input('cost_price')
                : null,
            'selling_price' => (int) $this->input('selling_price'),
            'unit' => $this->filled('unit') ? $this->string('unit')->toString() : 'pcs',
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
