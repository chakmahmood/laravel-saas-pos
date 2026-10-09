<?php

namespace App\Services;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderConflictException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Write path for payments (manual recording, no gateway).
 *
 * Recording and voiding lock the order row first, so concurrent payments for
 * the same order are serialized and overpayment cannot slip through on a
 * database that supports row locking (MySQL 8 InnoDB). The order's paid amount
 * and payment status are always recomputed from valid (non-voided) payments;
 * the client never sets them.
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

            if ($locked->fulfillment_status === FulfillmentStatus::CANCELLED) {
                throw new OrderConflictException(
                    'Order yang dibatalkan tidak dapat menerima pembayaran.',
                    'order_cancelled',
                );
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
                'payment_method' => $data['payment_method'],
                'amount' => $amount,
                'status' => PaymentRecordStatus::COMPLETED,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by' => $recorder->id,
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

            $order = Order::query()
                ->whereKey($lockedPayment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment->forceFill([
                'status' => PaymentRecordStatus::VOIDED,
                'voided_at' => now(),
                'voided_by' => $actor->id,
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
                'changed_by' => $actor->id,
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
}
