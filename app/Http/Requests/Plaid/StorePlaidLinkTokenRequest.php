<?php

namespace App\Http\Requests\Plaid;

use Illuminate\Foundation\Http\FormRequest;

class StorePlaidLinkTokenRequest extends FormRequest
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
            'import_history' => ['required', 'boolean'],
            'import_from' => ['exclude_unless:import_history,true', 'required', 'date', 'before_or_equal:today'],
        ];
    }
}
