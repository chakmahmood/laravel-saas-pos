<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    /**
     * List customers of the current store. Archived customers are excluded.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:name,created_at'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $customers = $store->customers()
            ->when(
                ($validated['search'] ?? '') !== '',
                function ($query) use ($validated) {
                    $term = $validated['search'];

                    $query->where(function ($query) use ($term) {
                        $query->where('name', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%');
                    });
                },
            )
            ->orderBy($validated['sort'] ?? 'name', $validated['direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $customer = $store->customers()->create($request->validated());

        return (new CustomerResource($customer))
            ->additional(['message' => 'Pelanggan berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $customer): CustomerResource
    {
        $model = $this->resolveCustomer($request, $customer);

        Gate::authorize('view', $model);

        return new CustomerResource($model);
    }

    public function update(UpdateCustomerRequest $request, string $customer): JsonResponse
    {
        $model = $this->resolveCustomer($request, $customer);

        $model->update($request->validated());

        return (new CustomerResource($model->refresh()))
            ->additional(['message' => 'Pelanggan berhasil diperbarui.'])
            ->response();
    }

    /**
     * Archive (soft delete) a customer. Orders keep their reference so history
     * is never broken. Customers are never hard deleted through the API.
     */
    public function destroy(Request $request, string $customer): JsonResponse
    {
        $model = $this->resolveCustomer($request, $customer);

        Gate::authorize('delete', $model);

        $model->delete();

        return response()->json([
            'message' => 'Pelanggan berhasil diarsipkan.',
        ]);
    }

    private function resolveCustomer(Request $request, string $customer): Customer
    {
        return $this
            ->currentStore($request)
            ->customers()
            ->findOrFail($customer);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
