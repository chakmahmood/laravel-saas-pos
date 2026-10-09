<?php

namespace App\Http\Requests\CashMovement;

use App\Enums\CashMovementType;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends FormRequest
{
    /**
     * Only the cashier who owns the shift may record a movement on it.
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $session = $store->cashSessions()->findOrFail($this->route('cashSession'));

        return (bool) $this->user()?->can('recordMovement', $session);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashMovementType::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::MAX],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
