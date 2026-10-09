<?php

namespace App\Support;

use App\Models\StockBalance;
use App\Models\StockMovement;

/**
 * Outcome of a stock mutation (opening, receipt, adjustment).
 *
 * - `movement`  : the appended ledger row, or NULL for a no-op adjustment.
 * - `balance`   : the balance state right after the operation.
 * - `idempotent`: true when an identical request was already applied and the
 *                 existing movement/effect is being returned instead of
 *                 applying it again.
 * - `noOp`      : true when the operation produced no change (e.g. an
 *                 adjustment whose counted quantity equals the current
 *                 on-hand), so no movement was written.
 */
final class StockMutationResult
{
    public function __construct(
        public readonly StockBalance $balance,
        public readonly ?StockMovement $movement = null,
        public readonly bool $idempotent = false,
        public readonly bool $noOp = false,
    ) {}
}
