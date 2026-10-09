<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'stock_location_id' => $this->stock_location_id,
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'idempotency_key' => $this->idempotency_key,
            'note' => $this->note,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'item' => ItemResource::make($this->whenLoaded('item')),
            'stock_location' => StockLocationResource::make($this->whenLoaded('stockLocation')),
        ];
    }
}
