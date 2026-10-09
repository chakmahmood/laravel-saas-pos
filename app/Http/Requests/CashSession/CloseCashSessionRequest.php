<?php

namespace App\Http\Requests\CashSession;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    /**
     * Resolve the shift through the current store relation so another tenant's
     * shift is treated exactly like a missing one (404).
     */
    public function authorize(): bool
    {
        $store = $this->attributes->get('current_store');

        if ($store === null) {
            return false;
        }

        $session = $store->cashSessions()->findOrFail($this->route('cashSession'));

        return (bool) $this->user()?->can('close', $session);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actual_cash' => ['required', 'integer', 'min:0', 'max:'.Money::MAX],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
