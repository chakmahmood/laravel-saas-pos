<?php

namespace App\Http\Controllers\Api;

use App\Enums\BusinessType;
use App\Enums\StoreRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CurrentStoreService;
use App\Services\StockLocationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly CurrentStoreService $currentStores,
        private readonly StockLocationProvisioner $stockLocations,
    ) {}

    /**
     * Register user + first store + owner membership + free subscription.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],

            'store_name' => [
                'required',
                'string',
                'max:150',
            ],

            'store_slug' => [
                'required',
                'string',
                'max:100',
                'alpha_dash',
                'unique:stores,slug',
            ],

            /*
             * Required. The two canonical groups are `retail` ("Toko &
             * Penjualan") and `service` ("Jasa & Servis"). Legacy aliases
             * (restaurant/laundry/repair/salon/other) are still accepted and
             * normalized during the transition so older clients keep working;
             * anything else is rejected.
             */
            'business_type' => [
                'required',
                'string',
                Rule::in(BusinessType::acceptedInputValues()),
            ],
        ]);

        $result = DB::transaction(function () use ($validated) {
            /*
             * 1. Create user.
             */
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            /*
             * 2. Create first store.
             */
            $store = Store::create([
                'owner_id' => $user->id,
                'name' => $validated['store_name'],
                'slug' => $validated['store_slug'],
                'business_type' => BusinessType::canonicalize(
                    $validated['business_type'],
                )->value,
            ]);

            /*
             * Official default-stock-location provisioning for inventory-capable
             * stores. Idempotent and race-safe; skipped for business types that
             * do not use inventory. Never done implicitly during checkout.
             */
            if ($store->business_type->usesInventory()) {
                $this->stockLocations->ensureDefaultForStore($store);
            }

            /*
             * 3. Create owner membership.
             */
            $user->stores()->attach($store->id, [
                'role' => StoreRole::OWNER->value,
                'is_active' => true,
            ]);

            /*
             * 4. Get active Free plan.
             */
            $freePlan = Plan::query()
                ->where('slug', 'free')
                ->where('is_active', true)
                ->firstOrFail();

            /*
             * 5. Create subscription.
             */
            $subscription = Subscription::create([
                'store_id' => $store->id,
                'plan_id' => $freePlan->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
            ]);

            /*
             * 6. Create Sanctum token.
             *
             * Token ini langsung diberi current_store_id
             * agar setelah register Flutter langsung berada
             * pada store pertama miliknya.
             */
            $accessToken = $user->createToken(
                'flutter-app'
            );

            $this->currentStores->persist(
                $accessToken->accessToken,
                $store->id,
            );

            $token = $accessToken->plainTextToken;

            return [
                'user' => $user,
                'store' => $store,
                'subscription' => $subscription->load('plan'),
                'token' => $token,
            ];
        });

        return response()->json([
            'message' => 'Registrasi berhasil.',

            'data' => [
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                ],

                'store' => [
                    'id' => $result['store']->id,
                    'name' => $result['store']->name,
                    'slug' => $result['store']->slug,
                    'business_type' => $result['store']->business_type->value,
                ],

                'subscription' => [
                    'plan' => [
                        'id' => $result['subscription']->plan->id,
                        'name' => $result['subscription']->plan->name,
                        'slug' => $result['subscription']->plan->slug,
                    ],

                    'status' => $result['subscription']->status,
                    'billing_cycle' => $result['subscription']->billing_cycle,
                    'starts_at' => $result['subscription']->starts_at?->toISOString(),
                ],

                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * Login user.
     *
     * Jika user memiliki beberapa store aktif,
     * untuk sementara store aktif pertama berdasarkan ID
     * akan digunakan sebagai current store.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
            ],

            'password' => [
                'required',
                'string',
            ],
        ]);

        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        if (
            ! $user ||
            ! Hash::check($validated['password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'Email atau password salah.',
                ],
            ]);
        }

        /*
         * Ambil store aktif pertama yang memang menjadi
         * membership user.
         */
        $currentStore = $user->stores()
            ->where('stores.is_active', true)
            ->wherePivot('is_active', true)
            ->orderBy('stores.id')
            ->first();

        /*
         * Buat token Sanctum.
         */
        $accessToken = $user->createToken(
            'flutter-app'
        );

        /*
         * Simpan current store pada token.
         *
         * Jika user belum memiliki store aktif,
         * nilainya akan NULL.
         */
        $this->currentStores->persist(
            $accessToken->accessToken,
            $currentStore?->id,
        );

        $token = $accessToken->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',

            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],

                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Logout current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ->currentAccessToken()
            ?->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Change the authenticated user's own password.
     *
     * Also clears the `must_change_password` flag so an employee who was forced
     * to rotate the initial password can use the POS afterwards.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Kata sandi saat ini tidak cocok.'],
            ]);
        }

        $user->forceFill([
            'password' => (string) $request->input('password'),
            'must_change_password' => false,
        ])->save();

        return response()->json([
            'message' => 'Kata sandi berhasil diubah.',
            'data' => [
                'must_change_password' => false,
            ],
        ]);
    }
}
