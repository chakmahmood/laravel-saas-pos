<?php

namespace App\Http\Resources;

use App\Support\StockMutationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Consistent response envelope for stock mutations (opening, receipt,
 * adjustment). Wraps a {@see StockMutationResult}.
 *
 * @mixin StockMutationResult
 */
class StockMutationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockMutationResult $result */
        $result = $this->resource;

        return [
            'movement' => $result->movement
                ? new StockMovementResource($result->movement)
                : null,
            'balance' => new StockBalanceResource($result->balance),
            'idempotent' => $result->idempotent,
            'no_op' => $result->noOp,
        ];
    }
}
