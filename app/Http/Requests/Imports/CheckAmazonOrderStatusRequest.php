<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class CheckAmazonOrderStatusRequest extends FormRequest
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
            'orderNumbers' => ['required', 'array', 'min:1', 'max:100'],
            'orderNumbers.*' => ['required', 'string', 'min:1', 'max:64'],
        ];
    }
}
