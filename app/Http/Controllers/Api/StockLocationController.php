<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreStockLocationRequest;
use App\Http\Requests\Stock\UpdateStockLocationRequest;
use App\Http\Resources\StockLocationResource;
use App\Models\Order;
use App\Models\StockLocation;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StockLocationController extends Controller
{
    /**
     * List stock locations of the current store.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StockLocation::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'string', 'max:20'],
            'is_active' => ['sometimes', 'in:true,false,1,0'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:name,created_at'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $locations = $store->stockLocations()
            ->when(
                ($validated['search'] ?? '') !== '',
                function ($query) use ($validated) {
                    $term = $validated['search'];

                    $query->where(function ($query) use ($term) {
                        $query->where('name', 'like', '%'.$term.'%')
                            ->orWhere('code', 'like', '%'.$term.'%');
                    });
                },
            )
            ->when(
                isset($validated['type']),
                fn ($query) => $query->where('type', $validated['type']),
            )
            ->when(
                $request->has('is_active'),
                fn ($query) => $query->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy($validated['sort'] ?? 'name', $validated['direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return StockLocationResource::collection($locations);
    }

    /**
     * Create a stock location in the current store.
     *
     * Ownership comes from the current store; `is_default` and `default_guard`
     * cannot be set by the client.
     */
    public function store(StoreStockLocationRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $location = $store->stockLocations()->create($request->locationAttributes());

        return (new StockLocationResource($location->refresh()))
            ->additional(['message' => 'Lokasi stok berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a stock location of the current store.
     */
    public function show(Request $request, string $stockLocation): StockLocationResource
    {
        $model = $this->resolveLocation($request, $stockLocation);

        Gate::authorize('view', $model);

        return new StockLocationResource($model);
    }

    /**
     * Update a stock location of the current store.
     */
    public function update(UpdateStockLocationRequest $request, string $stockLocation): JsonResponse
    {
        $model = $this->resolveLocation($request, $stockLocation);

        $model->update($request->locationAttributes());

        return (new StockLocationResource($model->refresh()))
            ->additional(['message' => 'Lokasi stok berhasil diperbarui.'])
            ->response();
    }

    /**
     * Delete a stock location of the current store.
     *
     * Refused (409) when the location is the active default, or when it is
     * referenced by balances, ledger movements or orders. The database foreign
     * keys are a backstop, not the only protection.
     */
    public function destroy(Request $request, string $stockLocation): JsonResponse
    {
        $model = $this->resolveLocation($request, $stockLocation);

        Gate::authorize('delete', $model);

        if ($model->is_default) {
            return $this->defaultProtectedResponse();
        }

        if ($this->isReferenced($model)) {
            return $this->locationInUseResponse();
        }

        try {
            $model->delete();
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                return $this->locationInUseResponse();
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Lokasi stok berhasil dihapus.',
        ]);
    }

    private function isReferenced(StockLocation $location): bool
    {
        if ($location->balances()->exists() || $location->movements()->exists()) {
            return true;
        }

        return Order::query()
            ->where('stock_location_id', $location->getKey())
            ->exists();
    }

    private function defaultProtectedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Lokasi default tidak dapat dihapus.',
            'code' => 'default_stock_location_protected',
        ], 409);
    }

    private function locationInUseResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Lokasi stok masih digunakan dan tidak dapat dihapus.',
            'code' => 'stock_location_in_use',
        ], 409);
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return $driverCode === 1451
            || str_contains(strtolower($exception->getMessage()), 'foreign key');
    }

    /**
     * Resolve a location strictly inside the current store. A missing id and an
     * id belonging to another tenant both yield 404.
     */
    private function resolveLocation(Request $request, string $stockLocation): StockLocation
    {
        return $this
            ->currentStore($request)
            ->stockLocations()
            ->findOrFail($stockLocation);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
