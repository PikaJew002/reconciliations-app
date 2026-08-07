<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderComponentRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['delivery', 'tip', 'tax', 'fee', 'other'])],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric'],
        ];
    }
}
