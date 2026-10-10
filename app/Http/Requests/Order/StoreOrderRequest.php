<?php

namespace App\Http\Requests\Order;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storeId = $this->currentStoreId();

        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')
                    ->where('store_id', $storeId)
                    ->whereNull('deleted_at'),
            ],

            /*
             * Optional client-generated key that makes order creation safe to
             * retry. Replaying the same key with the same payload returns the
             * original order instead of creating a duplicate; reusing the key
             * with a different payload is rejected as a conflict (409).
             */
            'idempotency_key' => ['nullable', 'string', 'max:100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.discount_amount' => [
                'sometimes',
                'integer',
                'min:0',
                'max:999999999999999',
            ],

            'tax_amount' => ['sometimes', 'integer', 'min:0', 'max:999999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
