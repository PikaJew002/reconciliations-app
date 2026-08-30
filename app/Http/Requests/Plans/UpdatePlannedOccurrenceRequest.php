<?php

namespace App\Http\Requests\Plans;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlannedOccurrenceRequest extends FormRequest
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
            'expected_date' => ['required', 'date'],
            'expected_amount' => ['required', 'numeric', 'min:0'],
            'month' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
