<?php

namespace App\Services;

use App\Enums\CashSessionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderConflictException;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for payments (manual recording, no gateway).
 *
 * Lock ordering is fixed to avoid deadlocks:
 *
 *   order -> cash_session -> payment
 *
 * `record()` locks the order and (for cash) the cashier's open shift, so a
 * concurrent close cannot let a cash payment slip in after the shift is
 * closed, and concurrent payments cannot overpay. `void()` follows the same
 * order and refuses to void a cash payment that belongs to a closed shift, so a
 * closed reconciliation is never silently changed.
 */
class PaymentService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws OrderConflictException
     */
    public function record(Order $order, User $recorder, array $data): Payment
    {
        return DB::transaction(function () use ($order, $recorder, $data) {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Idempotency: a replay with the same key and the same payload
             * returns the original payment and never records a second one. The
             * lookup runs after the order row lock, so concurrent retries for
             * the same order are serialized. This is checked before the
             * cash-session requirement so a genuine retry of an already
             * recorded cash payment does not fail after the shift has closed.
             */
            $idempotencyKey = $this->idempotencyKey($data);
            $fingerprint = $idempotencyKey !== null
                ? $this->paymentFingerprint($data)
                : null;

            if ($idempotencyKey !== null) {
                $existing = Payment::query()
                    ->where('store_id', $locked->store_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    if ($existing->request_fingerprint !== $fingerprint) {
                        throw new OrderConflictException(
                            'Idempotency key ini sudah dipakai untuk permintaan pembayaran yang berbeda.',
                            'idempotency_conflict',
                        );
                    }

                    return $existing;
                }
            }

            if ($locked->fulfillment_status === FulfillmentStatus::CANCELLED) {
                throw new OrderConflictException(
                    'Order yang dibatalkan tidak dapat menerima pembayaran.',
                    'order_cancelled',
                );
            }

            $isCash = ($data['payment_method'] ?? null) === PaymentMethod::CASH->value;

            /*
             * A cash payment must be attributed to the recorder's open shift.
             * Locking the shift row serializes against shift close.
             */
            $cashSession = null;

            if ($isCash) {
                $cashSession = CashSession::query()
                    ->where('store_id', $locked->store_id)
                    ->where('cashier_id', $recorder->getKey())
                    ->where('status', CashSessionStatus::OPEN->value)
                    ->lockForUpdate()
                    ->first();

                if ($cashSession === null) {
                    throw new OrderConflictException(
                        'Shift kas belum dibuka. Buka shift terlebih dahulu untuk menerima pembayaran tunai.',
                        'cash_session_required',
                    );
                }
            }

            $amount = (int) $data['amount'];
            $activePaid = $locked->activePaidAmount();
            $remaining = max(0, $locked->total_amount - $activePaid);

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Jumlah pembayaran melebihi sisa tagihan (Rp '.number_format($remaining, 0, ',', '.').').',
                ]);
            }

            $payment = $locked->payments()->create([
                'store_id' => $locked->store_id,
                'cash_session_id' => $cashSession?->getKey(),
                'payment_method' => $data['payment_method'],
                'amount' => $amount,
                'status' => PaymentRecordStatus::COMPLETED,
                'reference_number' => $data['reference_number'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'notes' => $data['notes'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by' => $recorder->getKey(),
            ]);

            $this->syncPaymentStatus($locked, $recorder, 'Pembayaran dicatat');

            return $payment;
        });
    }

    /**
     * Void a payment. The record is kept for audit; it no longer counts toward
     * the order paid amount.
     *
     * @throws OrderConflictException
     */
    public function void(Payment $payment, User $actor, ?string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason) {
            // Lock in the fixed order: order -> cash_session -> payment.
            $order = Order::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $cashSession = null;

            if ($payment->cash_session_id !== null) {
                $cashSession = CashSession::query()
                    ->whereKey($payment->cash_session_id)
                    ->lockForUpdate()
                    ->first();
            }

            $lockedPayment = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === PaymentRecordStatus::VOIDED) {
                throw new OrderConflictException(
                    'Pembayaran ini sudah dibatalkan.',
                    'payment_already_voided',
                );
            }

            /*
             * A cash payment tied to a closed shift must not be voided: it
             * would silently change a reconciliation that was already counted.
             */
            if ($cashSession !== null && $cashSession->status === CashSessionStatus::CLOSED) {
                throw new OrderConflictException(
                    'Pembayaran tunai pada shift yang sudah ditutup tidak dapat dibatalkan.',
                    'cash_session_closed',
                );
            }

            $lockedPayment->forceFill([
                'status' => PaymentRecordStatus::VOIDED,
                'voided_at' => now(),
                'voided_by' => $actor->getKey(),
                'void_reason' => $reason,
            ])->save();

            $this->syncPaymentStatus($order, $actor, 'Pembayaran dibatalkan');

            return $lockedPayment->refresh();
        });
    }

    /**
     * Recompute the order paid amount and payment status from valid payments.
     */
    private function syncPaymentStatus(Order $order, User $actor, string $reason): void
    {
        $paid = $order->activePaidAmount();
        $status = $this->derivePaymentStatus($order->total_amount, $paid);
        $previous = $order->payment_status;

        $order->forceFill([
            'paid_amount' => $paid,
            'payment_status' => $status,
        ])->save();

        if ($previous !== $status) {
            $order->statusHistories()->create([
                'from_fulfillment_status' => $order->fulfillment_status->value,
                'to_fulfillment_status' => $order->fulfillment_status->value,
                'from_payment_status' => $previous->value,
                'to_payment_status' => $status->value,
                'changed_by' => $actor->getKey(),
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }
    }

    private function derivePaymentStatus(int $total, int $paid): PaymentStatus
    {
        if ($total <= 0 || $paid >= $total) {
            return PaymentStatus::PAID;
        }

        if ($paid > 0) {
            return PaymentStatus::PARTIALLY_PAID;
        }

        return PaymentStatus::UNPAID;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Deterministic fingerprint of the payment payload, used to tell a safe
     * retry of the same request apart from a conflicting reuse of the key.
     *
     * @param  array<string, mixed>  $data
     */
    private function paymentFingerprint(array $data): string
    {
        return hash('sha256', (string) json_encode([
            'payment_method' => $data['payment_method'] ?? null,
            'amount' => (int) ($data['amount'] ?? 0),
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
        ]));
    }
}
