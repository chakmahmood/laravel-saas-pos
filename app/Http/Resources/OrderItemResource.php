<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_name' => $this->item_name,
            'item_sku' => $this->item_sku,
            'item_type' => $this->item_type->value,
            'unit' => $this->unit,
            'quantity' => (float) $this->quantity,
            'unit_price' => $this->unit_price,
            'line_subtotal' => $this->line_subtotal,
            'discount_amount' => $this->discount_amount,
            'line_total' => $this->line_total,
            'notes' => $this->notes,
        ];
    }
}
