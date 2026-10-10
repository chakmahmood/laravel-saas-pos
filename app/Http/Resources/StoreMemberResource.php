<?php

namespace App\Http\Resources;

use App\Models\StoreMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Store membership representation.
 *
 * Never exposes password material. The linked user's name/email come from the
 * eager-loaded `user` relation.
 *
 * @mixin StoreMember
 */
class StoreMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'role' => $this->role->value,
            'status' => $this->status()->value,
            'is_active' => (bool) $this->is_active,
            'must_change_password' => (bool) ($this->user?->must_change_password ?? false),
            'joined_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
