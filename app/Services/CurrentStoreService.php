<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Single source of truth for resolving and validating the current store
 * that is attached to a Sanctum token.
 *
 * All tenant-aware code must resolve the current store through this service
 * so membership and activation rules stay consistent and are never duplicated
 * inside individual controllers.
 */
class CurrentStoreService
{
    /**
     * Find a store that the user can actively access.
     *
     * A store is considered accessible only when:
     * - the store exists,
     * - the store is active,
     * - the user has an active membership (store_user.is_active) on it.
     */
    public function findAccessibleStore(User $user, int $storeId): ?Store
    {
        return $user->stores()
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->wherePivot('is_active', true)
            ->first();
    }

    /**
     * Resolve the current store stored on the token, validating access.
     *
     * Returns null when the token has no current store or when the stored
     * store is no longer accessible by the user.
     */
    public function resolveForToken(User $user, PersonalAccessToken $token): ?Store
    {
        if ($token->current_store_id === null) {
            return null;
        }

        return $this->findAccessibleStore($user, (int) $token->current_store_id);
    }

    /**
     * Persist the current store selection on the given token only.
     *
     * The selection is scoped to the token so that multiple devices or
     * sessions of the same user keep independent current stores.
     */
    public function persist(PersonalAccessToken $token, ?int $storeId): void
    {
        $current = $token->current_store_id === null
            ? null
            : (int) $token->current_store_id;

        if ($current === $storeId) {
            return;
        }

        $token->forceFill([
            'current_store_id' => $storeId,
        ])->save();
    }
}
