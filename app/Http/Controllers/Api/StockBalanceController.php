<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemStockResource;
use App\Http\Resources\StockBalanceResource;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockBalanceController extends Controller
{
    /**
     * List stock balances of the current store.
     *
     * Read-only. Only balances for stock-tracked items are returned. A missing
     * balance row is never created by a GET.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockBalance::class);

        $validated = $request->validate([
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'stock_location_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:updated_at,item_id,quantity_on_hand'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $balances = $store->stockBalances()
            ->with(['item', 'stockLocation'])
            ->whereHas('item', fn ($query) => $query->where('tracks_stock', true))
            ->when(
                isset($validated['item_id']),
                fn ($query) => $query->where('item_id', $validated['item_id']),
            )
            ->when(
                isset($validated['stock_location_id']),
                fn ($query) => $query->where('stock_location_id', $validated['stock_location_id']),
            )
            ->orderBy($validated['sort'] ?? 'updated_at', $validated['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return StockBalanceResource::collection($balances);
    }

    /**
     * Stock of a single catalog item across the store's locations.
     *
     * A non-tracked item is reported explicitly as `inventory_item_not_tracked`
     * (409) instead of returning a zero balance, which would wrongly imply the
     * physical stock is zero.
     */
    public function forItem(Request $request, string $item): JsonResponse
    {
        $model = $this->currentStore($request)
            ->items()
            ->findOrFail($item);

        Gate::authorize('view', $model);

        if (! $model->tracks_stock) {
            return response()->json([
                'message' => 'Item ini tidak melacak stok.',
                'code' => 'inventory_item_not_tracked',
            ], 409);
        }

        $balances = $model->stockBalances()
            ->with('stockLocation')
            ->orderBy('stock_location_id')
            ->get();

        $model->setRelation('stockBalances', $balances);

        return (new ItemStockResource($model))->response();
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
