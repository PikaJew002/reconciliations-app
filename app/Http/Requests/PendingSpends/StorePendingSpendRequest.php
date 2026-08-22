<?php

namespace App\Http\Requests\PendingSpends;

use App\Models\PendingSpend;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePendingSpendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'merchant_id' => $this->filled('merchant_id') ? $this->input('merchant_id') : null,
            'category_id' => $this->filled('category_id') ? $this->input('category_id') : null,
            'notes' => $this->filled('notes') ? $this->input('notes') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'source' => ['required', 'string', Rule::in(PendingSpend::sources())],
            'spent_at' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'merchant_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
