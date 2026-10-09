<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\StockLocation;
use App\Models\User;

/**
 * Authorizes stock location operations against the current store context.
 *
 * Role matrix:
 * - owner, admin : read and manage locations.
 * - cashier      : read only.
 *
 * Inventory capability (`BusinessType::usesInventory`) is enforced by the
 * `EnsureInventoryEnabled` middleware before the policy runs, so this policy
 * only decides role and tenant ownership.
 */
class StockLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, StockLocation $location): bool
    {
        return $this->role() !== null
            && $location->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->isManager();
    }

    public function update(User $user, StockLocation $location): bool
    {
        return $this->isManager()
            && $location->store_id === $this->currentStoreId();
    }

    public function delete(User $user, StockLocation $location): bool
    {
        return $this->isManager()
            && $location->store_id === $this->currentStoreId();
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
