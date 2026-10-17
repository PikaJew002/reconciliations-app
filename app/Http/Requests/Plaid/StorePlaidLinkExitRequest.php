<?php

namespace App\Http\Requests\Plaid;

use Illuminate\Foundation\Http\FormRequest;

class StorePlaidLinkExitRequest extends FormRequest
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
            'link_session_id' => ['nullable', 'string', 'max:255'],
            'institution_id' => ['nullable', 'string', 'max:255'],
            'error_type' => ['nullable', 'string', 'max:255'],
            'error_code' => ['nullable', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
