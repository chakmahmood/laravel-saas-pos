<?php

namespace App\Http\Resources;

use App\Models\StockBalance;
use App\Support\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockBalance
 */
class StockBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $onHand = Quantity::toMillis($this->quantity_on_hand);
        $reserved = Quantity::toMillis($this->quantity_reserved);

        return [
            'id' => $this->id,
            'stock_location_id' => $this->stock_location_id,
            'item_id' => $this->item_id,
            'quantity_on_hand' => $this->quantity_on_hand,
            'quantity_reserved' => $this->quantity_reserved,
            // available = on_hand - reserved, computed exactly (no float math).
            'quantity_available' => Quantity::fromMillis($onHand - $reserved),
            'updated_at' => $this->updated_at?->toISOString(),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'stock_location' => StockLocationResource::make($this->whenLoaded('stockLocation')),
        ];
    }
}
