<?php

namespace Tests\Concerns;

use App\Enums\CashSessionStatus;
use App\Enums\StoreRole;
use App\Models\CashSession;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

trait InteractsWithTenants
{
    /**
     * Register a hypothetical business endpoint guarded by the tenant
     * middleware. Used to assert tenant isolation until real business
     * modules (Category/Product) exist.
     */
    protected function defineBusinessRoute(): void
    {
        Route::middleware(['auth:sanctum', 'current.store'])
            ->match(['GET', 'POST'], '/api/_test/business', function (Request $request) {
                $store = $request->attributes->get('current_store');

                return response()->json([
                    'data' => [
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'role' => $request->attributes->get('current_store_role'),
                        'client_store_id' => $request->input('store_id'),
                    ],
                ]);
            });
    }

    protected function freePlan(): Plan
    {
        return Plan::factory()->free()->create();
    }

    protected function createStore(User $owner, array $attributes = []): Store
    {
        return Store::factory()
            ->for($owner, 'owner')
            ->create($attributes);
    }

    protected function attachMember(
        User $user,
        Store $store,
        string $role = StoreRole::OWNER->value,
        bool $active = true,
    ): void {
        $user->stores()->syncWithoutDetaching([
            $store->id => [
                'role' => $role,
                'is_active' => $active,
            ],
        ]);
    }

    /**
     * Create a user with a single owned store and an active owner membership.
     *
     * @return array{0: User, 1: Store}
     */
    protected function createOwnerWithStore(): array
    {
        $user = User::factory()->create();
        $store = $this->createStore($user);
        $this->attachMember($user, $store, StoreRole::OWNER->value, true);

        return [$user, $store];
    }

    /**
     * Issue a real Sanctum token and set its current store.
     */
    protected function issueToken(User $user, ?Store $store): string
    {
        $newToken = $user->createToken('test-token');

        $newToken->accessToken->forceFill([
            'current_store_id' => $store?->id,
        ])->save();

        return $newToken->plainTextToken;
    }

    protected function bearer(string $token): array
    {
        /*
         * The test kernel reuses the application container between calls, so
         * the auth guard caches the previously authenticated user. Real API
         * requests are stateless, so reset the guards before every request to
         * emulate that behaviour.
         */
        $this->app['auth']->forgetGuards();

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    protected function currentStoreIdOnToken(string $token): ?int
    {
        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->current_store_id === null
            ? null
            : (int) $accessToken->current_store_id;
    }

    protected function createUserWithPassword(string $password = 'password'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
        ]);
    }

    /**
     * Open an active cashier shift for the given user in the given store.
     *
     * Cash payments now require the recorder to have an open shift, so tests
     * that record cash open one first.
     */
    protected function openShiftFor(
        User $user,
        Store $store,
        int $openingCash = 0,
    ): CashSession {
        return CashSession::factory()->create([
            'store_id' => $store->id,
            'cashier_id' => $user->id,
            'status' => CashSessionStatus::OPEN->value,
            'opening_cash' => $openingCash,
            'opened_at' => now(),
            'open_guard' => $store->id.':'.$user->id,
        ]);
    }
}
