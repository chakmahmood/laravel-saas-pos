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

        $item = $this->catalogItems->create($store, $request->itemAttributes());

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
        $model = $this->resolveItem($request, $item);

        $model->update($request->itemAttributes());

        return (new ItemResource($model->refresh()))
            ->additional(['message' => 'Item berhasil diperbarui.'])
            ->response();
    }

    /**
     * Delete an item of the current store.
     */
    public function destroy(Request $request, string $item): JsonResponse
    {
        $model = $this->resolveItem($request, $item);

        Gate::authorize('delete', $model);

        $model->delete();

        return response()->json([
            'message' => 'Item berhasil dihapus.',
        ]);
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
