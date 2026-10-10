<?php

namespace App\Services;

use App\Enums\StoreRole;
use App\Exceptions\OrderConflictException;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Write path for store members.
 *
 * Invariants enforced here (never in the UI):
 * - Every operation is scoped to the store resolved by `current.store`, never
 *   to a client-supplied store id.
 * - Only `admin` and `cashier` roles can be created; `owner` never is.
 * - At most ONE active admin per store. The store row is locked first (project
 *   lock order: store -> child rows) so concurrent requests are serialized.
 * - The owner membership can never be demoted or deactivated through these
 *   flows, so a store never loses its owner by normal member management.
 * - Employee accounts are created together with their membership in a single
 *   transaction, so no orphan global account is left behind.
 */
class StoreMemberService
{
    /**
     * Create the single admin account of the store.
     *
     * @throws OrderConflictException
     */
    public function createAdmin(
        Store $store,
        string $name,
        string $email,
        string $password,
    ): StoreMember {
        return $this->createMember($store, StoreRole::ADMIN, $name, $email, $password);
    }

    /**
     * Create a cashier account of the store.
     *
     * @throws OrderConflictException
     */
    public function createCashier(
        Store $store,
        string $name,
        string $email,
        string $password,
    ): StoreMember {
        return $this->createMember($store, StoreRole::CASHIER, $name, $email, $password);
    }

    /**
     * Change a member role between admin and cashier.
     *
     * @throws OrderConflictException
     */
    public function changeRole(Store $store, StoreMember $member, StoreRole $role): StoreMember
    {
        return DB::transaction(function () use ($store, $member, $role): StoreMember {
            $lockedStore = $this->lockStore($store);

            $locked = $this->lockMember($lockedStore, $member);

            if ($locked->role === StoreRole::OWNER) {
                throw new OrderConflictException(
                    'Owner toko tidak dapat diubah melalui pengelolaan anggota.',
                    'owner_protected',
                );
            }

            if ($locked->role === $role) {
                return $locked->load('user');
            }

            if ($role === StoreRole::ADMIN) {
                $this->assertAdminSlotAvailable($lockedStore, $locked->getKey());
            }

            $locked->role = $role;
            $locked->save();

            return $locked->refresh()->load('user');
        });
    }

    /**
     * Activate or deactivate a member.
     *
     * @throws OrderConflictException
     */
    public function setActive(Store $store, StoreMember $member, bool $active): StoreMember
    {
        return DB::transaction(function () use ($store, $member, $active): StoreMember {
            $lockedStore = $this->lockStore($store);

            $locked = $this->lockMember($lockedStore, $member);

            if ($locked->role === StoreRole::OWNER) {
                throw new OrderConflictException(
                    'Owner toko tidak dapat dinonaktifkan melalui pengelolaan anggota.',
                    'owner_protected',
                );
            }

            if ((bool) $locked->is_active === $active) {
                return $locked->load('user');
            }

            if ($active && $locked->role === StoreRole::ADMIN) {
                $this->assertAdminSlotAvailable($lockedStore, $locked->getKey());
            }

            $locked->is_active = $active;
            $locked->save();

            return $locked->refresh()->load('user');
        });
    }

    /**
     * @throws OrderConflictException
     */
    private function createMember(
        Store $store,
        StoreRole $role,
        string $name,
        string $email,
        string $password,
    ): StoreMember {
        return DB::transaction(function () use ($store, $role, $name, $email, $password): StoreMember {
            $lockedStore = $this->lockStore($store);

            $normalized = Str::lower(trim($email));

            if (User::query()->where('email', $normalized)->exists()) {
                throw new OrderConflictException(
                    'Email sudah terdaftar. Gunakan email lain.',
                    'email_already_registered',
                );
            }

            if ($role === StoreRole::ADMIN) {
                $this->assertAdminSlotAvailable($lockedStore);
            }

            try {
                $user = User::query()->create([
                    'name' => trim($name),
                    'email' => $normalized,
                    'password' => $password,
                    'must_change_password' => true,
                ]);
            } catch (QueryException) {
                /*
                 * A concurrent request may have created the same email between
                 * the existence check and the insert. The unique index on
                 * users.email is the final guard; translate it to the same
                 * domain conflict instead of leaking a SQL error.
                 */
                throw new OrderConflictException(
                    'Email sudah terdaftar. Gunakan email lain.',
                    'email_already_registered',
                );
            }

            $member = StoreMember::query()->create([
                'store_id' => $lockedStore->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role->value,
                'is_active' => true,
            ]);

            return $member->refresh()->load('user');
        });
    }

    private function lockStore(Store $store): Store
    {
        return Store::query()
            ->whereKey($store->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockMember(Store $store, StoreMember $member): StoreMember
    {
        return StoreMember::query()
            ->whereKey($member->getKey())
            ->where('store_id', $store->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * At most one active admin per store. `owner` is a separate role and is not
     * counted as an admin.
     *
     * @param  int|null  $ignoreMemberId  membership being (re)activated
     *
     * @throws OrderConflictException
     */
    private function assertAdminSlotAvailable(Store $store, ?int $ignoreMemberId = null): void
    {
        $query = StoreMember::query()
            ->where('store_id', $store->getKey())
            ->where('role', StoreRole::ADMIN->value)
            ->where('is_active', true);

        if ($ignoreMemberId !== null) {
            $query->whereKeyNot($ignoreMemberId);
        }

        if ($query->exists()) {
            throw new OrderConflictException(
                'Toko ini sudah memiliki admin aktif. Nonaktifkan admin tersebut terlebih dahulu.',
                'admin_limit_reached',
            );
        }
    }
}
