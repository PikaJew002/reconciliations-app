<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveOrderPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:2'],
            'payments.*.index' => ['required', 'integer', 'min:0'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.bank_transaction_id' => ['nullable', 'integer', 'exists:bank_transactions,id'],
            'payments.*.kind' => ['nullable', 'string', Rule::in(['card', 'gift_card', 'walmart_balance', 'unknown'])],
        ];
    }
}
