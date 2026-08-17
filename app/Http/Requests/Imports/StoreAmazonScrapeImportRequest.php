<?php

namespace App\Http\Requests\Imports;

use Illuminate\Foundation\Http\FormRequest;

class StoreAmazonScrapeImportRequest extends FormRequest
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
            'scrapedAt' => ['nullable', 'string'],
            'summary' => ['nullable', 'array'],
            'details' => ['required', 'array'],
            'details.*.success' => ['sometimes', 'boolean'],
            'details.*.orderNumber' => ['nullable', 'string'],
            'details.*.data' => ['nullable', 'array'],
        ];
    }
}
