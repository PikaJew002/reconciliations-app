<?php

namespace App\Http\Requests\Onboarding;

use App\Services\Onboarding\OnboardingSteps;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SkipOnboardingStepRequest extends FormRequest
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
            'step' => [
                'required',
                'string',
                Rule::in(app(OnboardingSteps::class)->skippableKeys()),
            ],
        ];
    }
}
