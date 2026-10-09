<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\CashSession;
use App\Models\User;

/**
 * Cash session authorization.
 *
 * - viewAny : any active member.
 * - view    : owner/admin see any shift in the store; a cashier only their own.
 * - open    : any active member (opens their own shift).
 * - close   : owner/admin, or the cashier who owns the shift.
 * - recordMovement : only the cashier who owns the shift (no recording on
 *                    someone else's shift, even for owner/admin).
 */
class CashSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, CashSession $session): bool
    {
        return $this->role() !== null
            && $session->store_id === $this->currentStoreId()
            && ($this->isManager() || $session->cashier_id === $user->id);
    }

    public function open(User $user): bool
    {
        return $this->role() !== null;
    }

    public function close(User $user, CashSession $session): bool
    {
        return $this->role() !== null
            && $session->store_id === $this->currentStoreId()
            && ($this->isManager() || $session->cashier_id === $user->id);
    }

    public function recordMovement(User $user, CashSession $session): bool
    {
        return $this->role() !== null
            && $session->store_id === $this->currentStoreId()
            && $session->cashier_id === $user->id;
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
