<?php

namespace App\Http\Requests\Plaid;

use Illuminate\Foundation\Http\FormRequest;

class StorePlaidItemRequest extends FormRequest
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
            'public_token' => ['required', 'string'],
            'institution_id' => ['nullable', 'string', 'max:255'],
            'link_session_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
