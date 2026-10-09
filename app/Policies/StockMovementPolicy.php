<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Authorizes read access to the inventory ledger.
 *
 * The ledger is append-only and read-only through the API: any active member
 * (including a cashier) may read it, but there is no mutation ability to
 * authorize. Inventory capability is enforced by the
 * `EnsureInventoryEnabled` middleware.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->role() !== null
            && $movement->store_id === $this->currentStoreId();
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
