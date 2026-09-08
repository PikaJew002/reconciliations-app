<?php

namespace App\Http\Requests\Plans;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOccurrenceCarryForwardRequest extends FormRequest
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
            'carry_forward' => ['nullable', 'numeric'],
            'month' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function carryForward(): ?float
    {
        $value = $this->validated('carry_forward') ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    public function viewMonth(): ?string
    {
        $month = $this->validated('month') ?? null;

        return is_string($month) && $month !== '' ? $month : null;
    }
}
