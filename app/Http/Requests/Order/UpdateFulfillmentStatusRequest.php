<?php

namespace App\Http\Requests\Order;

use App\Enums\FulfillmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFulfillmentStatusRequest extends FormRequest
{
    /**
     * Changing fulfillment status is operational (any member). Cancelling an
     * order is destructive and restricted to owner/admin.
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $order = $store->orders()->findOrFail($this->route('order'));

        $requested = FulfillmentStatus::tryFrom((string) $this->input('fulfillment_status'));

        if ($requested === FulfillmentStatus::CANCELLED) {
            return (bool) $this->user()?->can('cancel', $order);
        }

        return (bool) $this->user()?->can('updateFulfillment', $order);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fulfillment_status' => ['required', Rule::enum(FulfillmentStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
