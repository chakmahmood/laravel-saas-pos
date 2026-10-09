<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\User;

/**
 * Authorizes category operations against the current store context.
 *
 * The current store and role are resolved by the `current.store` middleware
 * and exposed on the request attributes. They are always server-derived and
 * can never be supplied by the client.
 *
 * Role matrix (Phase 1a):
 * - owner, admin : read and manage categories.
 * - cashier      : read only (for POS operations).
 *
 * The role is read at call time through the `request()` helper instead of
 * constructor injection, because Gate caches policy instances across requests
 * within the same application lifecycle (e.g. during tests).
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Category $category): bool
    {
        return $this->role() !== null
            && $category->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->isManager();
    }

    public function update(User $user, Category $category): bool
    {
        return $this->isManager()
            && $category->store_id === $this->currentStoreId();
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->isManager()
            && $category->store_id === $this->currentStoreId();
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
