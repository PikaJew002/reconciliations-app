<?php

namespace App\Http\Requests\Plans;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeftoverOriginRequest extends FormRequest
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
            'month' => ['required', 'date_format:Y-m'],
            'view_month' => ['nullable', 'date_format:Y-m'],
            'carry_over' => ['sometimes', 'nullable', 'numeric'],
        ];
    }

    public function month(): string
    {
        return $this->validated('month');
    }

    public function viewMonth(): ?string
    {
        $month = $this->validated('view_month') ?? null;

        return is_string($month) && $month !== '' ? $month : null;
    }

    public function hasCarryOver(): bool
    {
        return $this->exists('carry_over');
    }

    public function carryOver(): float
    {
        return round((float) ($this->validated('carry_over') ?? 0), 2);
    }
}
