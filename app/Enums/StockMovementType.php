<?php

namespace App\Enums;

/**
 * Inventory ledger movement type.
 *
 * Quantity is always stored positive; the type alone determines the effect on
 * the balance. Direction helpers are provided so the ledger service (next
 * checkpoint) never hard-codes the mapping.
 *
 * On-hand effect:
 * - opening            : initial stock entered into the ledger.
 * - purchase_in        : stock received from a supplier / stock-in.
 * - sale_out           : stock consumed by a completed sale.
 * - adjustment_in      : positive stock correction (opname).
 * - adjustment_out     : negative stock correction (opname / shrinkage).
 * - transfer_in        : stock received from another location.
 * - transfer_out       : stock sent to another location.
 * - return_in          : goods returned by a customer (back into stock).
 * - return_out         : goods returned to a supplier.
 *
 * Reservation effect (no change to physical on-hand):
 * - reservation        : stock held for an order that is not final yet.
 * - reservation_release: held stock released (order cancelled before commit).
 *
 * Special:
 * - reversal           : corrects a previous movement; its direction is the
 *                        opposite of the movement referenced by
 *                        `stock_movements.reversal_of_id`.
 */
enum StockMovementType: string
{
    case OPENING = 'opening';
    case PURCHASE_IN = 'purchase_in';
    case SALE_OUT = 'sale_out';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';
    case TRANSFER_IN = 'transfer_in';
    case TRANSFER_OUT = 'transfer_out';
    case RETURN_IN = 'return_in';
    case RETURN_OUT = 'return_out';
    case RESERVATION = 'reservation';
    case RESERVATION_RELEASE = 'reservation_release';
    case REVERSAL = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::OPENING => 'Stok Awal',
            self::PURCHASE_IN => 'Pembelian Masuk',
            self::SALE_OUT => 'Penjualan Keluar',
            self::ADJUSTMENT_IN => 'Penyesuaian Masuk',
            self::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
            self::TRANSFER_IN => 'Transfer Masuk',
            self::TRANSFER_OUT => 'Transfer Keluar',
            self::RETURN_IN => 'Retur Masuk',
            self::RETURN_OUT => 'Retur Keluar',
            self::RESERVATION => 'Reservasi',
            self::RESERVATION_RELEASE => 'Pelepasan Reservasi',
            self::REVERSAL => 'Pembalik',
        };
    }

    /**
     * Whether this movement increases physical on-hand stock.
     */
    public function increasesOnHand(): bool
    {
        return in_array($this, [
            self::OPENING,
            self::PURCHASE_IN,
            self::ADJUSTMENT_IN,
            self::TRANSFER_IN,
            self::RETURN_IN,
        ], true);
    }

    /**
     * Whether this movement decreases physical on-hand stock.
     */
    public function decreasesOnHand(): bool
    {
        return in_array($this, [
            self::SALE_OUT,
            self::ADJUSTMENT_OUT,
            self::TRANSFER_OUT,
            self::RETURN_OUT,
        ], true);
    }

    /**
     * Whether this movement increases the reserved quantity.
     */
    public function increasesReserved(): bool
    {
        return $this === self::RESERVATION;
    }

    /**
     * Whether this movement decreases the reserved quantity.
     */
    public function decreasesReserved(): bool
    {
        return $this === self::RESERVATION_RELEASE;
    }

    /**
     * A reversal has no fixed direction; it is the opposite of the movement
     * it references. The ledger service must resolve the original movement.
     */
    public function isReversal(): bool
    {
        return $this === self::REVERSAL;
    }
}
