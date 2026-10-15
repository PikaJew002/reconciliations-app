<?php

namespace App\Http\Requests\Reconciliation;

use App\Services\Reconciliation\OrderPaymentResolutionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderPaymentRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(OrderPaymentResolutionService::addableOffBookKinds())],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
