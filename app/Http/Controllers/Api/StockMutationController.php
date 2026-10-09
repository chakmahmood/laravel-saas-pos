<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreAdjustmentRequest;
use App\Http\Requests\Stock\StoreOpeningBalanceRequest;
use App\Http\Requests\Stock\StoreReceiptRequest;
use App\Http\Resources\StockMutationResource;
use App\Models\Store;
use App\Services\StockLedgerService;
use App\Support\StockMutationResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock-in mutations: opening stock, manual receipt and physical-count
 * adjustment.
 *
 * All writes go through {@see StockLedgerService}; controllers never touch
 * balances directly. Permission is owner/admin only and the store must support
 * inventory (enforced by the `EnsureInventoryEnabled` middleware on the route
 * group).
 */
class StockMutationController extends Controller
{
    public function __construct(
        private readonly StockLedgerService $ledger,
    ) {}

    public function openingBalance(StoreOpeningBalanceRequest $request): JsonResponse
    {
        $result = $this->ledger->recordOpening(
            $this->currentStore($request),
            (int) $request->input('stock_location_id'),
            (int) $request->input('item_id'),
            $request->input('quantity'),
            $request->string('idempotency_key')->toString(),
            $request->user(),
            $request->input('note'),
        );

        return $this->respond($result, 'Saldo awal stok berhasil dicatat.');
    }

    public function receipt(StoreReceiptRequest $request): JsonResponse
    {
        $result = $this->ledger->recordReceipt(
            $this->currentStore($request),
            (int) $request->input('stock_location_id'),
            (int) $request->input('item_id'),
            $request->input('quantity'),
            $request->string('idempotency_key')->toString(),
            $request->user(),
            $request->input('reference'),
        );

        return $this->respond($result, 'Barang masuk berhasil dicatat.');
    }

    public function adjustment(StoreAdjustmentRequest $request): JsonResponse
    {
        $result = $this->ledger->recordAdjustment(
            $this->currentStore($request),
            (int) $request->input('stock_location_id'),
            (int) $request->input('item_id'),
            $request->input('counted_quantity'),
            $request->string('idempotency_key')->toString(),
            $request->user(),
            $request->input('reason'),
        );

        return $this->respond($result, 'Penyesuaian stok berhasil dicatat.');
    }

    private function respond(StockMutationResult $result, string $message): JsonResponse
    {
        $result->balance->loadMissing(['item', 'stockLocation']);
        $result->movement?->loadMissing(['item', 'stockLocation']);

        // 201 for a new mutation; 200 for an idempotent retry or a documented
        // no-op (nothing new was created).
        $status = ($result->idempotent || $result->noOp) ? 200 : 201;

        return (new StockMutationResource($result))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode($status);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
