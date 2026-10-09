<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock overview for one catalog item, including its per-location balances.
 *
 * The `stockBalances` relation is expected to be pre-loaded by the caller.
 *
 * @mixin Item
 */
class ItemStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'tracks_stock' => $this->tracks_stock,
            'balances' => StockBalanceResource::collection($this->whenLoaded('stockBalances')),
        ];
    }
}
