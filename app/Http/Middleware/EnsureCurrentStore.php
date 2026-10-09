<?php

namespace App\Http\Middleware;

use App\Services\CurrentStoreService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the authenticated Sanctum token has a valid, accessible current store.
 *
 * This middleware must only be applied to business endpoints that require a
 * tenant context. It is intentionally not applied to register, login, logout,
 * /api/me, or the current-store endpoints themselves, because those endpoints
 * either do not need a tenant or are used to select one.
 *
 * On success the validated store is exposed to controllers through the request
 * attributes:
 *
 *     $store = $request->attributes->get('current_store');
 *     $role  = $request->attributes->get('current_store_role');
 */
class EnsureCurrentStore
{
    public function __construct(
        private readonly CurrentStoreService $currentStores,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->error(
                'Tidak terautentikasi.',
                'unauthenticated',
                401,
            );
        }

        $token = $user->currentAccessToken();

        /*
         * Business endpoints require a persistent API token that can hold a
         * per-token store context. Session / transient tokens are rejected so
         * the current store is never taken from a client-supplied value.
         */
        if (! $token instanceof PersonalAccessToken) {
            return $this->error(
                'Endpoint ini memerlukan token API dengan konteks toko.',
                'token_required',
                401,
            );
        }

        if ($token->current_store_id === null) {
            return $this->error(
                'Toko aktif belum dipilih pada token ini.',
                'current_store_not_selected',
                409,
            );
        }

        $store = $this->currentStores->resolveForToken($user, $token);

        /*
         * The token points to a store that is deleted, inactive, or where the
         * membership was revoked. We clear the stale reference and return the
         * same generic response for every reason so tenant existence is never
         * leaked to the caller.
         */
        if ($store === null) {
            $this->currentStores->persist($token, null);

            return $this->error(
                'Toko aktif pada token ini tidak lagi tersedia. Silakan pilih toko lain.',
                'current_store_unavailable',
                409,
            );
        }

        $request->attributes->set('current_store', $store);
        $request->attributes->set('current_store_role', $store->pivot->role);

        return $next($request);
    }

    private function error(string $message, string $code, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
