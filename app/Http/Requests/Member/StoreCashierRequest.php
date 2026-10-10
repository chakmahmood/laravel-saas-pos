<?php

namespace App\Http\Requests\Member;

use App\Models\StoreMember;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a cashier account of the active store (owner or admin).
 *
 * The role is fixed by the endpoint, never chosen by the client.
 */
class StoreCashierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('createCashier', StoreMember::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
