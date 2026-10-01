<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $this->user() !== null
            && $account instanceof Account
            && $account->user_id === $this->user()->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_name' => $this->filled('account_name') ? $this->input('account_name') : null,
            'last_four' => $this->filled('last_four') ? $this->input('last_four') : null,
            'currency' => $this->filled('currency')
                ? strtoupper((string) $this->input('currency'))
                : $this->input('currency'),
            'default_classification' => $this->filled('default_classification')
                ? $this->input('default_classification')
                : BankTransaction::CLASSIFICATION_EXPENSE,
        ]);

        if (! $this->exists('card_aliases')) {
            return;
        }

        $aliases = $this->input('card_aliases');

        if (is_string($aliases)) {
            $aliases = preg_split('/[\s,]+/', $aliases) ?: [];
        }

        if (! is_array($aliases)) {
            return;
        }

        $this->merge([
            'card_aliases' => array_values(array_unique(array_filter(
                array_map(fn (mixed $value): string => trim((string) $value), $aliases),
                fn (string $value): bool => $value !== '',
            ))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...(new Account)->validationRules(),
            'card_aliases' => ['sometimes', 'array', 'max:20'],
            'card_aliases.*' => ['distinct', 'digits:4'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lastFour = $this->input('last_four');
            $aliases = $this->input('card_aliases', []);

            if (! is_string($lastFour) || ! is_array($aliases) || ! in_array($lastFour, $aliases, true)) {
                return;
            }

            $validator->errors()->add(
                'card_aliases',
                'The account last four is already saved on the account.',
            );
        });
    }
}
