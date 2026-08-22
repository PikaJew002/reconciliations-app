<?php

namespace App\Http\Requests\Merchants;

use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Merchants\MerchantBrowseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateMerchantMatchingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchant = $this->route('merchant');
        $rule = $this->route('rule');

        if ($this->user() === null
            || ! $merchant instanceof Merchant
            || ! $rule instanceof MerchantMatchingRule) {
            return false;
        }

        if ($rule->merchant_id !== $merchant->id
            || $rule->user_id !== $this->user()->id
            || ! app(MerchantBrowseService::class)->isBrowsable($this->user()->id, $merchant)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('pattern')) {
            $this->merge([
                'pattern' => MerchantMatchingRule::normalizePattern((string) $this->input('pattern')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rule = $this->route('rule');
        $ruleId = $rule instanceof MerchantMatchingRule ? $rule->id : null;
        $matchMode = $this->input('match_mode', $rule instanceof MerchantMatchingRule ? $rule->match_mode : null);

        return [
            'is_active' => ['sometimes', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(MerchantMatchingRule::matchModes())],
            'pattern' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('merchant_matching_rules', 'pattern')
                    ->where('user_id', $this->user()?->id)
                    ->where('match_mode', $matchMode)
                    ->ignore($ruleId),
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
