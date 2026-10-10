<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999999'],

            /*
             * Optional client-generated key that makes recording a payment safe
             * to retry: a replay returns the original payment, never a second
             * one. Reusing the key with a different payload is a 409.
             */
            'idempotency_key' => ['nullable', 'string', 'max:100'],

            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
