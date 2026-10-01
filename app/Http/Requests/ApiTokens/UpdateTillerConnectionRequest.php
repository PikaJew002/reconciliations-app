<?php

namespace App\Http\Requests\ApiTokens;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTillerConnectionRequest extends FormRequest
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
            'callback_url' => ['required', 'string', 'url', 'max:2048', 'starts_with:https://'],
        ];
    }
}
