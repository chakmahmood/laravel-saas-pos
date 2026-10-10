<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\StoreRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a user in a store (the `store_user` pivot with metadata).
 *
 * A dedicated model is used so membership operations can be authorized with a
 * policy, returned through an API resource, and mutated through a service that
 * protects the "one active admin per store" invariant.
 */
class StoreMember extends Model
{
    use HasFactory;

    protected $table = 'store_user';

    protected $fillable = [
        'store_id',
        'user_id',
        'role',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'role' => StoreRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): MembershipStatus
    {
        return $this->is_active
            ? MembershipStatus::ACTIVE
            : MembershipStatus::INACTIVE;
    }

    public function isActiveAdmin(): bool
    {
        return $this->role === StoreRole::ADMIN && $this->is_active;
    }
}
