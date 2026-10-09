<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Item\StoreItemRequest;
use App\Http\Requests\Item\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Models\Store;
use App\Services\CatalogItemService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function __construct(
        private readonly CatalogItemService $catalogItems,
    ) {}

    /**
     * List items of the current store.
     *
     * Supports pagination, name/SKU/barcode search, type filter, category
     * filter, active filter, and stable ordering. Every query is scoped to the
     * current store.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Item::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(ItemType::class)],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'in:true,false,1,0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:name,created_at,selling_price'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $items = $store->items()
            ->when(
                ($validated['search'] ?? '') !== '',
                function ($query) use ($validated) {
                    $term = $validated['search'];

                    $query->where(function ($query) use ($term) {
                        $query->where('name', 'like', '%'.$term.'%')
                            ->orWhere('sku', 'like', '%'.$term.'%')
                            ->orWhere('barcode', 'like', '%'.$term.'%');
                    });
                },
            )
            ->when(
                isset($validated['type']),
                fn ($query) => $query->where('type', $validated['type']),
            )
            ->when(
                isset($validated['category_id']),
                fn ($query) => $query->where('category_id', $validated['category_id']),
            )
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy($validated['sort'] ?? 'name', $validated['direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ItemResource::collection($items);
    }

    /**
     * Create an item in the current store.
     *
     * Ownership and quota are enforced server-side through the catalog
     * service, never from request input.
     */
    public function store(StoreItemRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $attributes = $request->itemAttributes();

        if (($attributes['tracks_stock'] ?? false) === true && ! $store->business_type->usesInventory()) {
            return $this->inventoryUnavailableResponse();
        }

        $item = $this->catalogItems->create($store, $attributes);

        return (new ItemResource($item))
            ->additional(['message' => 'Item berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show an item of the current store.
     */
    public function show(Request $request, string $item): ItemResource
    {
        $model = $this->resolveItem($request, $item);

        Gate::authorize('view', $model);

        return new ItemResource($model);
    }

    /**
     * Replace or partially update an item of the current store.
     *
     * The quota is intentionally not re-checked: updating an existing item must
     * never be blocked because the store is already at its item limit.
     */
    public function update(UpdateItemRequest $request, string $item): JsonResponse
    {
        $store = $this->currentStore($request);
        $model = $this->resolveItem($request, $item);

        $attributes = $request->itemAttributes();

        if (array_key_exists('tracks_stock', $attributes)) {
            if ($attributes['tracks_stock'] === true && ! $store->business_type->usesInventory()) {
                return $this->inventoryUnavailableResponse();
            }

            if (
                $attributes['tracks_stock'] === false
                && $model->tracks_stock
                && ($model->stockBalances()->exists() || $model->stockMovements()->exists())
            ) {
                return $this->itemInventoryInUseResponse();
            }
        }

        $model->update($attributes);

        return (new ItemResource($model->refresh()))
            ->additional(['message' => 'Item berhasil diperbarui.'])
            ->response();
    }

    /**
     * Delete an item of the current store.
     *
     * An item that already has stock history (balances or ledger movements)
     * cannot be deleted: doing so would corrupt the inventory audit trail. The
     * relation is checked first for a clean 409; the RESTRICT foreign keys on
     * the inventory tables are the backstop and their violation is translated
     * into the same 409. To stop using an item without touching history, set it
     * inactive instead.
     */
    public function destroy(Request $request, string $item): JsonResponse
    {
        $model = $this->resolveItem($request, $item);

        Gate::authorize('delete', $model);

        if ($model->stockBalances()->exists() || $model->stockMovements()->exists()) {
            return $this->itemHasStockHistoryResponse();
        }

        try {
            $model->delete();
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                return $this->itemHasStockHistoryResponse();
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Item berhasil dihapus.',
        ]);
    }

    private function itemHasStockHistoryResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Item memiliki riwayat stok dan tidak dapat dihapus. Nonaktifkan item untuk menghentikan penggunaannya.',
            'code' => 'item_has_stock_history',
        ], 409);
    }

    private function inventoryUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Fitur inventory tidak tersedia untuk toko ini.',
            'code' => 'inventory_not_available',
        ], 403);
    }

    private function itemInventoryInUseResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Item tidak dapat berhenti melacak stok karena sudah memiliki saldo atau riwayat stok.',
            'code' => 'item_inventory_in_use',
        ], 409);
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1451
            || str_contains(strtolower($exception->getMessage()), 'foreign key');
    }

    /**
     * Resolve an item strictly inside the current store.
     *
     * A missing id and an id belonging to another tenant behave identically
     * (404), never leaking tenant existence.
     */
    private function resolveItem(Request $request, string $item): Item
    {
        return $this
            ->currentStore($request)
            ->items()
            ->findOrFail($item);
    }

    /**
     * Current store resolved by the `current.store` middleware.
     */
    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
