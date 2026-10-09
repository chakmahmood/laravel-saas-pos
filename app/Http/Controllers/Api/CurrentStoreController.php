<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentStoreController extends Controller
{
    /**
     * Get current active store for the current Sanctum token.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (!$token || !$token->current_store_id) {
            return response()->json([
                'message' => 'Belum ada toko aktif.',
                'data' => [
                    'current_store' => null,
                ],
            ]);
        }

        $store = $user->stores()
            ->where('stores.id', $token->current_store_id)
            ->where('stores.is_active', true)
            ->wherePivot('is_active', true)
            ->first();

        if (!$store) {
            $token->forceFill([
                'current_store_id' => null,
            ])->save();

            return response()->json([
                'message' => 'Toko aktif tidak ditemukan.',
                'data' => [
                    'current_store' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'current_store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'role' => $store->pivot->role,
                    'is_active' => $store->is_active,
                ],
            ],
        ]);
    }

    /**
     * Change current active store for the current Sanctum token.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => [
                'required',
                'integer',
                'exists:stores,id',
            ],
        ]);

        $user = $request->user();

        /*
         * Jangan hanya mengecek apakah store ada.
         *
         * Kita harus memastikan user memang memiliki
         * membership aktif pada store tersebut.
         */
        $store = $user->stores()
            ->where('stores.id', $validated['store_id'])
            ->where('stores.is_active', true)
            ->wherePivot('is_active', true)
            ->first();

        if (!$store) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke toko tersebut.',
            ], 403);
        }

        $token = $user->currentAccessToken();

        if (!$token) {
            return response()->json([
                'message' => 'Token akses tidak ditemukan.',
            ], 401);
        }

        /*
         * Simpan store aktif ke Sanctum token.
         */
        $token->forceFill([
            'current_store_id' => $store->id,
        ])->save();

        return response()->json([
            'message' => 'Toko aktif berhasil diubah.',
            'data' => [
                'current_store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'role' => $store->pivot->role,
                    'is_active' => $store->is_active,
                ],
            ],
        ]);
    }
}

