<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class StoreVenmoActivityImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
