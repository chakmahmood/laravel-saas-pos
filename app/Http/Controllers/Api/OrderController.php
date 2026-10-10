<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateFulfillmentStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Store;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Order::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'fulfillment_status' => ['sometimes', Rule::enum(FulfillmentStatus::class)],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'cashier_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:placed_at,created_at,order_number,total_amount'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $orders = $store->orders()
            ->with(['items', 'customer'])
            ->when(
                ($validated['search'] ?? '') !== '',
                fn ($query) => $query->where('order_number', 'like', '%'.$validated['search'].'%'),
            )
            ->when(
                isset($validated['payment_status']),
                fn ($query) => $query->where('payment_status', $validated['payment_status']),
            )
            ->when(
                isset($validated['fulfillment_status']),
                fn ($query) => $query->where('fulfillment_status', $validated['fulfillment_status']),
            )
            ->when(
                isset($validated['customer_id']),
                fn ($query) => $query->where('customer_id', $validated['customer_id']),
            )
            ->when(
                isset($validated['cashier_id']),
                fn ($query) => $query->where('cashier_id', $validated['cashier_id']),
            )
            ->when(
                isset($validated['date_from']),
                fn ($query) => $query->where('placed_at', '>=', $validated['date_from'].' 00:00:00'),
            )
            ->when(
                isset($validated['date_to']),
                fn ($query) => $query->where('placed_at', '<=', $validated['date_to'].' 23:59:59'),
            )
            ->orderBy($validated['sort'] ?? 'placed_at', $validated['direction'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $order = $this->orders->create($store, $request->user(), $request->validated());

        $order->load(['items', 'customer']);

        return (new OrderResource($order))
            ->additional(['message' => 'Order berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $order): OrderResource
    {
        $model = $this->resolveOrder($request, $order);

        Gate::authorize('view', $model);

        $model->load(['items', 'customer', 'payments']);

        return new OrderResource($model);
    }

    /**
     * Read-only reconciliation by client idempotency key.
     *
     * Lets a cashier determine the true outcome of a checkout whose response was
     * lost (timeout / dropped connection) without creating or mutating anything.
     * The lookup is scoped to the active store, so a key belonging to another
     * tenant is indistinguishable from a missing key (404, no leak).
     */
    public function reconcile(Request $request): OrderResource|JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $order = $this->currentStore($request)
            ->orders()
            ->where('idempotency_key', $validated['idempotency_key'])
            ->with(['items', 'customer', 'payments'])
            ->first();

        if ($order === null) {
            return response()->json([
                'message' => 'Transaksi dengan kunci idempotensi tersebut tidak ditemukan pada toko ini.',
                'code' => 'order_not_found',
            ], 404);
        }

        return new OrderResource($order);
    }

    /**
     * Advance the fulfillment status. Cancellation has its own authorization
     * rule and rejects orders with active payments.
     */
    public function updateFulfillment(
        UpdateFulfillmentStatusRequest $request,
        string $order,
    ): JsonResponse {
        $model = $this->resolveOrder($request, $order);

        $status = FulfillmentStatus::from((string) $request->input('fulfillment_status'));

        $updated = $this->orders->changeFulfillment(
            $model,
            $status,
            $request->input('reason'),
            $request->user(),
        );

        $updated->load(['items', 'customer']);

        return (new OrderResource($updated))
            ->additional(['message' => 'Status pemenuhan order berhasil diperbarui.'])
            ->response();
    }

    private function resolveOrder(Request $request, string $order): Order
    {
        return $this
            ->currentStore($request)
            ->orders()
            ->findOrFail($order);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
