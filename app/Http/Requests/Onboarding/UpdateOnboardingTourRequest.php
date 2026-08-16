<?php

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\OnboardingSteps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingTourRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(['completed', 'dismissed'])],
            'key' => [
                'required',
                'string',
                Rule::in(app(OnboardingSteps::class)->tourKeys()),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => $this->route('key'),
        ]);
    }
}
