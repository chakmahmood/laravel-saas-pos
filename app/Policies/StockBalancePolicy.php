<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\StockBalance;
use App\Models\User;

/**
 * Authorizes read access to stock balances.
 *
 * Balances are read-only in this phase: any active member (including a cashier)
 * may read them, but there is no mutation ability to authorize. Inventory
 * capability is enforced by the `EnsureInventoryEnabled` middleware.
 */
class StockBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, StockBalance $balance): bool
    {
        return $this->role() !== null
            && $balance->store_id === $this->currentStoreId();
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
