<?php

namespace App\Http\Controllers\Api;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    /**
     * Read-only inventory ledger of the current store.
     *
     * The ledger is append-only: there is no create/update/delete endpoint.
     * New movements are produced by the ledger service (order flows, and later
     * stock receipt/adjustment), never by the API client directly.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockMovement::class);

        $validated = $request->validate([
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'stock_location_id' => ['sometimes', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::enum(StockMovementType::class)],
            'order_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:occurred_at,created_at,id'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $movements = $store->stockMovements()
            ->with(['item', 'stockLocation'])
            ->when(
                isset($validated['item_id']),
                fn ($query) => $query->where('item_id', $validated['item_id']),
            )
            ->when(
                isset($validated['stock_location_id']),
                fn ($query) => $query->where('stock_location_id', $validated['stock_location_id']),
            )
            ->when(
                isset($validated['type']),
                fn ($query) => $query->where('type', $validated['type']),
            )
            ->when(
                isset($validated['order_id']),
                fn ($query) => $query->where('order_id', $validated['order_id']),
            )
            ->when(
                isset($validated['date_from']),
                fn ($query) => $query->where('occurred_at', '>=', $validated['date_from'].' 00:00:00'),
            )
            ->when(
                isset($validated['date_to']),
                fn ($query) => $query->where('occurred_at', '<=', $validated['date_to'].' 23:59:59'),
            )
            ->orderBy($validated['sort'] ?? 'occurred_at', $validated['direction'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return StockMovementResource::collection($movements);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
