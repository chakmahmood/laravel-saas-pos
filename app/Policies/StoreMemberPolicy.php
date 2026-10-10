<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\StoreMember;
use App\Models\User;

/**
 * Authorizes store member management against the current store context.
 *
 * Role matrix (team management):
 * - owner   : view members, create admin (one slot), create cashier, change
 *             roles (admin <-> cashier), activate/deactivate members.
 * - admin   : view members, create cashier, activate/deactivate cashiers.
 * - cashier : no access to member management.
 *
 * The current store and role are resolved by the `current.store` middleware and
 * read from request attributes at call time (Gate caches policy instances
 * across requests within one application lifecycle).
 *
 * Guards for "owner cannot be managed" and the one-active-admin limit are
 * enforced in the service as domain conflicts (409), not here, so callers get a
 * stable machine-readable code.
 */
class StoreMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner() || $this->isAdmin();
    }

    public function view(User $user, StoreMember $member): bool
    {
        return ($this->isOwner() || $this->isAdmin())
            && $this->belongsToCurrentStore($member);
    }

    /**
     * Only the owner may create the single admin account.
     */
    public function createAdmin(User $user): bool
    {
        return $this->isOwner();
    }

    /**
     * Owner and admin may create cashier accounts.
     */
    public function createCashier(User $user): bool
    {
        return $this->isOwner() || $this->isAdmin();
    }

    /**
     * Only the owner may change a role. The owner membership itself is
     * protected by the service (409 owner_protected).
     */
    public function updateRole(User $user, StoreMember $member): bool
    {
        return $this->isOwner()
            && $this->belongsToCurrentStore($member);
    }

    /**
     * Owner may activate/deactivate any member (the owner membership itself is
     * protected by the service); admin may only manage cashiers.
     */
    public function updateStatus(User $user, StoreMember $member): bool
    {
        if (! $this->belongsToCurrentStore($member)) {
            return false;
        }

        if ($this->isOwner()) {
            return true;
        }

        return $this->isAdmin() && $member->role === StoreRole::CASHIER;
    }

    private function belongsToCurrentStore(StoreMember $member): bool
    {
        $storeId = $this->currentStoreId();

        return $storeId !== null && (int) $member->store_id === $storeId;
    }

    private function isOwner(): bool
    {
        return $this->role() === StoreRole::OWNER;
    }

    private function isAdmin(): bool
    {
        return $this->role() === StoreRole::ADMIN;
    }

    private function role(): ?StoreRole
    {
        $role = request()->attributes->get('current_store_role');

        return is_string($role) ? StoreRole::tryFrom($role) : null;
    }

    private function currentStoreId(): ?int
    {
        $store = request()->attributes->get('current_store');

        return $store?->id;
    }
}
