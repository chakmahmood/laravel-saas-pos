<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\CurrentStoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CurrentStoreController extends Controller
{
    public function __construct(
        private readonly CurrentStoreService $currentStores,
    ) {}

    /**
     * Get the current active store for the current Sanctum token.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Endpoint ini memerlukan token API dengan konteks toko.',
                'code' => 'token_required',
            ], 401);
        }

        if ($token->current_store_id === null) {
            return response()->json([
                'data' => [
                    'current_store' => null,
                ],
            ]);
        }

        $store = $this->currentStores->resolveForToken($user, $token);

        /*
         * The token references a store that is no longer accessible. Clear the
         * stale reference so the client is forced to pick a valid store again.
         */
        if ($store === null) {
            $this->currentStores->persist($token, null);

            return response()->json([
                'message' => 'Toko aktif tidak lagi tersedia.',
                'code' => 'current_store_unavailable',
                'data' => [
                    'current_store' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'current_store' => $this->transform($store),
            ],
        ]);
    }

    /**
     * Change the current active store for the current Sanctum token.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => [
                'required',
                'integer',
            ],
        ]);

        $user = $request->user();
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Endpoint ini memerlukan token API dengan konteks toko.',
                'code' => 'token_required',
            ], 401);
        }

        /*
         * Access is resolved through the membership relation, never from the
         * client-supplied value alone. A non-existent store and a store the
         * user cannot access intentionally share the same response so tenant
         * existence is not leaked.
         */
        $store = $this->currentStores->findAccessibleStore(
            $user,
            (int) $validated['store_id'],
        );

        if ($store === null) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke toko tersebut.',
                'code' => 'store_not_accessible',
            ], 403);
        }

        /*
         * Persist the selection on the requesting token only, so another
         * device/token belonging to the same user is unaffected.
         */
        $this->currentStores->persist($token, $store->id);

        return response()->json([
            'message' => 'Toko aktif berhasil diubah.',
            'data' => [
                'current_store' => $this->transform($store),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'role' => $store->pivot->role,
            'is_active' => $store->is_active,
        ];
    }
}
