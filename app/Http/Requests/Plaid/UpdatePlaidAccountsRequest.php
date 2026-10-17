<?php

namespace App\Http\Requests\Plaid;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlaidAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array'],
            'mappings.*.id' => ['required', 'uuid'],
            'mappings.*.account_id' => ['nullable', 'uuid'],
        ];
    }
}
