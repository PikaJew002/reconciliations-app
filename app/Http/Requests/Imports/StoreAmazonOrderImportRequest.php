<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class StoreAmazonOrderImportRequest extends FormRequest
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
            'summary_file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
            'items_file' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ];
    }
}
