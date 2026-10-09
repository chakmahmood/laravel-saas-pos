<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\Item;
use App\Models\User;

/**
 * Authorizes catalog item operations against the current store context.
 *
 * Role matrix (Phase 1b):
 * - owner, admin : read and manage items.
 * - cashier      : read only (needed to operate the POS).
 *
 * The role and current store are resolved by the `current.store` middleware
 * and read from request attributes at call time (Gate caches policy instances
 * across requests within one application lifecycle).
 */
class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Item $item): bool
    {
        return $this->role() !== null
            && $item->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->isManager();
    }

    public function update(User $user, Item $item): bool
    {
        return $this->isManager()
            && $item->store_id === $this->currentStoreId();
    }

    public function delete(User $user, Item $item): bool
    {
        return $this->isManager()
            && $item->store_id === $this->currentStoreId();
    }

    private function isManager(): bool
    {
        return in_array(
            $this->role(),
            [StoreRole::OWNER, StoreRole::ADMIN],
            true,
        );
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
