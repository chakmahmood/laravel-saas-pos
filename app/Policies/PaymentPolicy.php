<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\Payment;
use App\Models\User;

/**
 * Payment authorization.
 *
 * - record payment : every active member (cashiers collect payment).
 * - view           : every active member.
 * - void payment   : owner and admin only (financial correction).
 *
 * Payments are never deleted.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->role() !== null
            && $payment->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->role() !== null;
    }

    public function void(User $user, Payment $payment): bool
    {
        return $this->isManager()
            && $payment->store_id === $this->currentStoreId();
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
