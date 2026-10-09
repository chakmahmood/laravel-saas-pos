<?php

namespace App\Policies;

use App\Enums\StoreRole;
use App\Models\Customer;
use App\Models\User;

/**
 * Customer authorization.
 *
 * - owner, admin : read and manage customers.
 * - cashier      : read only. Cashiers are not granted customer deletion or
 *                  mutation by default.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role() !== null;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->role() !== null
            && $customer->store_id === $this->currentStoreId();
    }

    public function create(User $user): bool
    {
        return $this->isManager();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->isManager()
            && $customer->store_id === $this->currentStoreId();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->isManager()
            && $customer->store_id === $this->currentStoreId();
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
