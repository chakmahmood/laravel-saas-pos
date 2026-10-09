<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Authorizes access to the inventory ledger.
 *
 * - read (viewAny/view) : any active member.
 * - create              : owner/admin only (opening stock, receipt, adjustment).
 *
 * The ledger stays append-only: there is no update/delete ability at all.
 * Inventory capability is enforced by the `EnsureInventoryEnabled` middleware.
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

    public function create(User $user): bool
    {
        return $this->isManager();
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
