<?php

namespace App\Http\Resources;

use App\Models\CashSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashSession
 */
class CashSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cashIn = (int) ($this->cash_in_total ?? 0);
        $cashOut = (int) ($this->cash_out_total ?? 0);
        $cashSales = (int) ($this->cash_sales_total ?? 0);

        /*
         * Closed shifts use the stored reconciliation snapshot so history never
         * changes retroactively. Open shifts compute live from the aggregates.
         */
        $expected = $this->expected_cash
            ?? ($this->opening_cash + $cashIn - $cashOut + $cashSales);

        return [
            'id' => $this->id,
            'cashier_id' => $this->cashier_id,
            'status' => $this->status->value,
            'opening_cash' => $this->opening_cash,
            'cash_in_total' => $cashIn,
            'cash_out_total' => $cashOut,
            'cash_sales_total' => $cashSales,
            'expected_cash' => $expected,
            'actual_cash' => $this->actual_cash,
            'difference' => $this->difference,
            'opened_at' => $this->opened_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'opening_notes' => $this->opening_notes,
            'closing_notes' => $this->closing_notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
