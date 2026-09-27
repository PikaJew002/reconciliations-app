<?php

namespace App\Http\Requests\Reconciliation;

use App\Models\OrderComponent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderComponentRefundRequest extends FormRequest
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
            'refund_amount' => ['required', 'numeric', 'gt:0'],
            'refund_kind' => ['required', 'string', Rule::in([
                OrderComponent::REFUND_KIND_BANK,
                OrderComponent::REFUND_KIND_OFF_BOOK,
            ])],
        ];
    }
}
