<?php

namespace App\Http\Requests\Plans;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreVacationWindowRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function name(): ?string
    {
        $name = trim((string) ($this->validated('name') ?? ''));

        return $name !== '' ? $name : null;
    }

    public function startsOn(): string
    {
        return Carbon::parse($this->validated('starts_on'))->toDateString();
    }

    public function endsOn(): string
    {
        return Carbon::parse($this->validated('ends_on'))->toDateString();
    }

    public function viewMonth(): ?string
    {
        $month = $this->validated('month') ?? null;

        return is_string($month) && $month !== '' ? $month : null;
    }
}
