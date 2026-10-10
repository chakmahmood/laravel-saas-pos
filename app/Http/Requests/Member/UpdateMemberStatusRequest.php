<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activate or deactivate a member (owner: any non-owner; admin: cashier only).
 */
class UpdateMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $member = $store->members()->findOrFail($this->route('member'));

        return (bool) $this->user()?->can('updateStatus', $member);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
