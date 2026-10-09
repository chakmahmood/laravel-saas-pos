<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\Order;
use App\Models\User;

/**
 * Order authorization.
 *
 * - create / view : every active member (cashier operates the POS).
 * - change fulfillment status : every active member (operational).
 * - cancel : owner and admin only (destructive / financial).
 *
 * There is no order deletion endpoint; orders are cancelled, never removed.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->role() !== null
            && $order->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->role() !== null;
    }

    public function updateFulfillment(User $user, Order $order): bool
    {
        return $this->role() !== null
            && $order->store_id === $this->currentStoreId();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->isManager()
            && $order->store_id === $this->currentStoreId();
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
