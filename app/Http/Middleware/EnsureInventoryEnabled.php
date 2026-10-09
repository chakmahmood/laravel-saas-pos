<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject inventory endpoints for stores whose business type does not support
 * inventory.
 *
 * Runs AFTER `current.store`, so the active store is already validated and
 * exposed on the request attributes. The check is centralized here (and in
 * StockLedgerService for the order flow) instead of being spread across every
 * controller.
 *
 * Responds with HTTP 403 and a stable machine-readable code, so clients can
 * distinguish "you are not allowed" from "not found" or "not authenticated".
 */
class EnsureInventoryEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->attributes->get('current_store');

        if ($store === null || ! $store->business_type->usesInventory()) {
            return response()->json([
                'message' => 'Fitur inventory tidak tersedia untuk toko ini.',
                'code' => 'inventory_not_available',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
