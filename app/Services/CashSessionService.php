<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Exceptions\OrderConflictException;
use App\Models\CashSession;
use App\Models\Store;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for cashier shifts.
 *
 * Opening is protected by a database-level unique guard (`cash_sessions.open_guard`)
 * so two concurrent opens for the same store/cashier cannot both succeed, even
 * on MySQL. Closing locks the session row and recomputes the expected cash from
 * the aggregates inside the same transaction, so a concurrent payment cannot
 * slip past a close.
 */
class CashSessionService
{
    /**
     * @throws OrderConflictException
     * @throws ValidationException
     */
    public function open(
        Store $store,
        User $cashier,
        int $openingCash,
        ?string $openingNotes,
    ): CashSession {
        if ($openingCash < 0 || ! Money::withinBounds($openingCash)) {
            throw ValidationException::withMessages([
                'opening_cash' => 'Modal awal tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($store, $cashier, $openingCash, $openingNotes) {
            $existing = CashSession::query()
                ->where('store_id', $store->getKey())
                ->where('cashier_id', $cashier->getKey())
                ->where('status', CashSessionStatus::OPEN->value)
                ->exists();

            if ($existing) {
                throw new OrderConflictException(
                    'Anda masih memiliki shift yang terbuka di toko ini.',
                    'cash_session_already_open',
                );
            }

            try {
                return CashSession::create([
                    'store_id' => $store->getKey(),
                    'cashier_id' => $cashier->getKey(),
                    'status' => CashSessionStatus::OPEN,
                    'opening_cash' => $openingCash,
                    'opened_at' => now(),
                    'opening_notes' => $openingNotes,
                    'open_guard' => $this->openGuard($store, $cashier),
                ]);
            } catch (QueryException $exception) {
                if ($this->isOpenGuardViolation($exception)) {
                    throw new OrderConflictException(
                        'Anda masih memiliki shift yang terbuka di toko ini.',
                        'cash_session_already_open',
                    );
                }

                throw $exception;
            }
        });
    }

    /**
     * Close a shift and store the reconciliation result.
     *
     * @throws OrderConflictException
     */
    public function close(
        CashSession $session,
        User $actor,
        int $actualCash,
        ?string $closingNotes,
    ): CashSession {
        return DB::transaction(function () use ($session, $actualCash, $closingNotes) {
            $locked = CashSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CashSessionStatus::CLOSED) {
                throw new OrderConflictException(
                    'Shift ini sudah ditutup.',
                    'cash_session_already_closed',
                );
            }

            $expected = $this->expectedCash($locked);
            $difference = $actualCash - $expected;

            if (! Money::withinBounds($difference)) {
                throw ValidationException::withMessages([
                    'actual_cash' => 'Nilai kas fisik di luar batas yang diizinkan.',
                ]);
            }

            $locked->forceFill([
                'status' => CashSessionStatus::CLOSED,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'actual_cash' => $actualCash,
                'difference' => $difference,
                'closing_notes' => $closingNotes,
                'open_guard' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Compute expected cash from the session aggregates.
     *
     * expected = opening_cash + cash_in - cash_out + valid_cash_payments
     */
    public function expectedCash(CashSession $session): int
    {
        $cashIn = (int) $session->movements()
            ->where('type', CashMovementType::CASH_IN->value)
            ->sum('amount');

        $cashOut = (int) $session->movements()
            ->where('type', CashMovementType::CASH_OUT->value)
            ->sum('amount');

        $cashSales = (int) $session->cashPayments()->sum('amount');

        $expected = $session->opening_cash + $cashIn - $cashOut + $cashSales;

        if (! Money::withinBounds($expected)) {
            throw ValidationException::withMessages([
                'expected_cash' => 'Nilai kas di luar batas yang diizinkan.',
            ]);
        }

        return $expected;
    }

    private function isOpenGuardViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'open_guard')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }

    private function openGuard(Store $store, User $cashier): string
    {
        return $store->getKey().':'.$cashier->getKey();
    }
}
