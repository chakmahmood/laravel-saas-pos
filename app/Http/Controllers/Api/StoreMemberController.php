<?php

namespace App\Http\Controllers\Api;

use App\Enums\MembershipStatus;
use App\Enums\StoreRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreAdminRequest;
use App\Http\Requests\Member\StoreCashierRequest;
use App\Http\Requests\Member\UpdateMemberRoleRequest;
use App\Http\Requests\Member\UpdateMemberStatusRequest;
use App\Http\Resources\StoreMemberResource;
use App\Models\Store;
use App\Models\StoreMember;
use App\Services\StoreMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Team management for the active store.
 *
 * The active store is always resolved by the `current.store` middleware; the
 * client can never choose the tenant. Role authorization is delegated to
 * StoreMemberPolicy and the domain invariants (one active admin, owner
 * protection) to StoreMemberService.
 */
class StoreMemberController extends Controller
{
    public function __construct(
        private readonly StoreMemberService $members,
    ) {}

    /**
     * List members of the active store (owner and admin).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', StoreMember::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['sometimes', Rule::enum(StoreRole::class)],
            'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:created_at,role'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $store = $this->currentStore($request);

        $query = $store->members()->with('user');

        if (($validated['search'] ?? '') !== '') {
            $term = $validated['search'];

            $query->whereHas('user', function ($userQuery) use ($term): void {
                $userQuery->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (isset($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (isset($validated['status'])) {
            $active = MembershipStatus::from($validated['status']) === MembershipStatus::ACTIVE;
            $query->where('is_active', $active);
        }

        $members = $query
            ->orderBy($validated['sort'] ?? 'created_at', $validated['direction'] ?? 'asc')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return StoreMemberResource::collection($members);
    }

    /**
     * Show a member of the active store (owner and admin).
     */
    public function show(Request $request, string $member): StoreMemberResource
    {
        $model = $this->resolveMember($request, $member);

        Gate::authorize('view', $model);

        $model->load('user');

        return new StoreMemberResource($model);
    }

    /**
     * Create the single admin account of the active store (owner only).
     */
    public function storeAdmin(StoreAdminRequest $request): JsonResponse
    {
        $member = $this->members->createAdmin(
            $this->currentStore($request),
            (string) $request->input('name'),
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        return (new StoreMemberResource($member))
            ->additional(['message' => 'Akun admin berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Create a cashier account of the active store (owner or admin).
     */
    public function storeCashier(StoreCashierRequest $request): JsonResponse
    {
        $member = $this->members->createCashier(
            $this->currentStore($request),
            (string) $request->input('name'),
            (string) $request->input('email'),
            (string) $request->input('password'),
        );

        return (new StoreMemberResource($member))
            ->additional(['message' => 'Akun kasir berhasil dibuat.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Change a member role between admin and cashier (owner only).
     */
    public function updateRole(UpdateMemberRoleRequest $request, string $member): JsonResponse
    {
        $model = $this->resolveMember($request, $member);

        $updated = $this->members->changeRole(
            $this->currentStore($request),
            $model,
            StoreRole::from((string) $request->input('role')),
        );

        return (new StoreMemberResource($updated))
            ->additional(['message' => 'Role anggota berhasil diperbarui.'])
            ->response();
    }

    /**
     * Activate or deactivate a member.
     */
    public function updateStatus(UpdateMemberStatusRequest $request, string $member): JsonResponse
    {
        $model = $this->resolveMember($request, $member);

        $updated = $this->members->setActive(
            $this->currentStore($request),
            $model,
            $request->boolean('is_active'),
        );

        $message = $updated->status() === MembershipStatus::ACTIVE
            ? 'Anggota berhasil diaktifkan.'
            : 'Anggota berhasil dinonaktifkan.';

        return (new StoreMemberResource($updated))
            ->additional(['message' => $message])
            ->response();
    }

    private function resolveMember(Request $request, string $member): StoreMember
    {
        return $this->currentStore($request)->members()->findOrFail($member);
    }

    private function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('current_store');

        return $store;
    }
}
