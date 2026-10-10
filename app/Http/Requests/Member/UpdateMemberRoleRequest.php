<?php

namespace App\Http\Requests\Member;

use App\Enums\StoreRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a member role between admin and cashier (owner only).
 *
 * `owner` is never assignable, and the owner membership itself is protected by
 * the service (which returns 409 owner_protected).
 */
class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $member = $store->members()->findOrFail($this->route('member'));

        return (bool) $this->user()?->can('updateRole', $member);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'required',
                Rule::enum(StoreRole::class),
                Rule::notIn([StoreRole::OWNER->value]),
            ],
        ];
    }
}
