<?php

namespace App\Http\Requests\CashSession;

use App\Models\CashSession;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('open', CashSession::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'integer', 'min:0', 'max:'.Money::MAX],
            'opening_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
