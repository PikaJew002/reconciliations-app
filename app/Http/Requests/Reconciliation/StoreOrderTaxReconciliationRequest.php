<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderTaxReconciliationRequest extends FormRequest
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
            'rate' => ['required', 'numeric', 'min:0', 'max:1', 'decimal:0,5'],
            'component_ids' => ['present', 'array'],
            'component_ids.*' => ['integer'],
        ];
    }
}
