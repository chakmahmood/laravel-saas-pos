<?php

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Exceptions\OrderConflictException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for manual cash movements.
 *
 * Every movement is appended to an OPEN shift. The session row is locked to
 * re-check the open state, so a movement cannot be recorded while the shift is
 * being closed. Ownership is enforced by policy; the movement's store and user
 * are always taken from the server context.
 */
class CashMovementService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws OrderConflictException
     * @throws ValidationException
     */
    public function record(CashSession $session, User $user, array $data): CashMovement
    {
        $amount = (int) $data['amount'];

        if ($amount <= 0 || ! Money::withinBounds($amount)) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah kas harus lebih besar dari nol dan dalam batas yang wajar.',
            ]);
        }

        return DB::transaction(function () use ($session, $user, $data, $amount) {
            $locked = CashSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== CashSessionStatus::OPEN) {
                throw new OrderConflictException(
                    'Shift sudah ditutup dan tidak dapat menerima pergerakan kas.',
                    'cash_session_closed',
                );
            }

            return $locked->movements()->create([
                'store_id' => $locked->store_id,
                'user_id' => $user->getKey(),
                'type' => $data['type'],
                'amount' => $amount,
                'reason' => $data['reason'],
            ]);
        });
    }
}
