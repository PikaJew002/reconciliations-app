<?php

namespace App\Http\Requests\Merchants;

use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Merchants\MerchantBrowseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreMerchantMatchingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchant = $this->route('merchant');

        if ($this->user() === null || ! $merchant instanceof Merchant) {
            return false;
        }

        if (! app(MerchantBrowseService::class)->isBrowsable($this->user()->id, $merchant)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'pattern' => MerchantMatchingRule::normalizePattern((string) $this->input('pattern')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'match_mode' => ['required', Rule::in(MerchantMatchingRule::matchModes())],
            'pattern' => [
                'required',
                'string',
                'max:255',
                Rule::unique('merchant_matching_rules', 'pattern')
                    ->where('user_id', $this->user()?->id)
                    ->where('match_mode', $this->input('match_mode')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pattern.unique' => 'This pattern is already used by a merchant matching rule.',
        ];
    }
}
