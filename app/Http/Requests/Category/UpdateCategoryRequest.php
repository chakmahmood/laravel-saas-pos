<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * Owner and admin may update categories. Cashier may not.
     *
     * The category is resolved through the current store relation so that a
     * category of another tenant is treated exactly like a missing category
     * (404), never leaking its existence.
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $category = $store->categories()->findOrFail($this->route('category'));

        return (bool) $this->user()?->can('update', $category);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unique = Rule::unique('categories', 'name')
            ->where('store_id', $this->currentStoreId())
            ->ignore($this->route('category'));

        return [
            'name' => $this->isMethod('PUT')
                ? ['required', 'string', 'max:100', $unique]
                : ['sometimes', 'required', 'string', 'max:100', $unique],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function currentStoreId(): ?int
    {
        return $this->attributes->get('current_store')?->id;
    }
}
