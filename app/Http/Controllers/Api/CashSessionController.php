<?php

namespace App\Http\Controllers\Api;

use App\Enums\CashSessionStatus;
use App\Enums\StoreRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashMovement\StoreCashMovementRequest;
use App\Http\Requests\CashSession\CloseCashSessionRequest;
use App\Http\Requests\CashSession\OpenCashSessionRequest;
use App\Http\Resources\CashMovementResource;
use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\Store;
use App\Services\CashMovementService;
use App\Services\CashSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CashSessionController extends Controller
{
    public function __construct(
        private readonly CashSessionService $cashSessions,
        private readonly CashMovementService $cashMovements,
    ) {}

    /**
     * The current user's open shift in the active store (or null).
     */
    public function current(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CashSession::class);

        $session = $this->currentStore($request)->cashSessions()
            ->where('cashier_id', $request->user()->getKey())
            ->where('status', CashSessionStatus::OPEN->value)
            ->withSummary()
            ->first();

        return response()->json([
            'data' => [
                'current_cash_session' => $session
                    ? new CashSessionResource($session)
                    : null,
            ],
        ]);
    }

    /**
     * List shifts of the active store. A cashier only sees their own.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashSession::class);

        $validated = $request->validate([
            'cashier_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(CashSessionStatus::class)],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $store = $this->currentStore($request);

        $query = $store->cashSessions()->withSummary();

        if ($this->isManager($request)) {
            if (isset($validated['cashier_id'])) {
                $query->where('cashier_id', $validated['cashier_id']);
            }
        } else {
            $query->where('cashier_id', $request->user()->getKey());
        }

        $query
            ->when(
                isset($validated['status']),
                fn ($q) => $q->where('status', $validated['status']),
            )
            ->when(
                isset($validated['date_from']),
                fn ($q) => $q->where('opened_at', '>=', $validated['date_from'].' 00:00:00'),
            )
            ->when(
                isset($validated['date_to']),
                fn ($q) => $q->where('opened_at', '<=', $validated['date_to'].' 23:59:59'),
            )
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        $sessions = $query->paginate($validated['per_page'] ?? 15)->withQueryString();

        return CashSessionResource::collection($sessions);
    }

    public function open(OpenCashSessionRequest $request): JsonResponse
    {
        $store = $this->currentStore($request);

        $session = $this->cashSessions->open(
            $store,
            $request->user(),
            (int) $request->input('opening_cash'),
            $request->input('opening_notes'),
        );

        return (new CashSessionResource($session))
            ->additional(['message' => 'Shift kas berhasil dibuka.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $cashSession): CashSessionResource
    {
        $session = $this->resolveSession($request, $cashSession);

        Gate::authorize('view', $session);

        return new CashSessionResource($session);
    }

    public function close(CloseCashSessionRequest $request, string $cashSession): JsonResponse
    {
        $session = $this->resolveSession($request, $cashSession);

        $closed = $this->cashSessions->close(
            $session,
            $request->user(),
            (int) $request->input('actual_cash'),
            $request->input('closing_notes'),
        );

        // Reload aggregates so the response includes the full cash summary.
        $closed = CashSession::query()
            ->withSummary()
            ->whereKey($closed->getKey())
            ->firstOrFail();

        return (new CashSessionResource($closed))
            ->additional(['message' => 'Shift kas berhasil ditutup.'])
            ->response();
    }

    public function movements(Request $request, string $cashSession): AnonymousResourceCollection
    {
        $session = $this->resolveSession($request, $cashSession);

        Gate::authorize('view', $session);

        return CashMovementResource::collection(
            $session->movements()->orderBy('id')->get(),
        );
    }

    public function storeMovement(
        StoreCashMovementRequest $request,
        string $cashSession,
    ): JsonResponse {
        $session = $this->resolveSession($request, $cashSession);

        $movement = $this->cashMovements->record(
            $session,
            $request->user(),
            $request->validated(),
        );

        return (new CashMovementResource($movement))
            ->additional(['message' => 'Pergerakan kas berhasil dicatat.'])
            ->response()
            ->setStatusCode(201);
    }

    private function resolveSession(Request $request, string $cashSession): CashSession
    {
        return $this
            ->currentStore($request)
            ->cashSessions()
            ->withSummary()
            ->findOrFail($cashSession);
    }

    private function isManager(Request $request): bool
    {
        $role = StoreRole::tryFrom((string) $request->attributes->get('current_store_role'));

        return in_array($role, [StoreRole::OWNER, StoreRole::ADMIN], true);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
