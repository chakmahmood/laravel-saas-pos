<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\VoidPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * List payments recorded for an order.
     */
    public function indexForOrder(Request $request, string $order): AnonymousResourceCollection
    {
        $model = $this->resolveOrder($request, $order);

        Gate::authorize('view', $model);

        return PaymentResource::collection(
            $model->payments()->orderBy('id')->get(),
        );
    }

    /**
     * Record a manual payment for an order.
     */
    public function store(StorePaymentRequest $request, string $order): JsonResponse
    {
        $model = $this->resolveOrder($request, $order);

        $payment = $this->payments->record($model, $request->user(), $request->validated());

        return (new PaymentResource($payment))
            ->additional(['message' => 'Pembayaran berhasil dicatat.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $payment): PaymentResource
    {
        $model = $this->resolvePayment($request, $payment);

        Gate::authorize('view', $model);

        return new PaymentResource($model);
    }

    /**
     * Void a payment (audit kept). Owner/admin only.
     */
    public function void(VoidPaymentRequest $request, string $payment): JsonResponse
    {
        $model = $this->resolvePayment($request, $payment);

        $voided = $this->payments->void(
            $model,
            $request->user(),
            $request->input('reason'),
        );

        return (new PaymentResource($voided))
            ->additional(['message' => 'Pembayaran berhasil dibatalkan.'])
            ->response();
    }

    private function resolveOrder(Request $request, string $order): Order
    {
        return $this->currentStore($request)->orders()->findOrFail($order);
    }

    private function resolvePayment(Request $request, string $payment): Payment
    {
        return $this->currentStore($request)->payments()->findOrFail($payment);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
