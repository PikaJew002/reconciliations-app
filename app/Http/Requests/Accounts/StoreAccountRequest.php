<?php

namespace App\Http\Requests\Accounts;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_name' => $this->filled('account_name') ? $this->input('account_name') : null,
            'last_four' => $this->filled('last_four') ? $this->input('last_four') : null,
            'currency' => $this->filled('currency')
                ? strtoupper((string) $this->input('currency'))
                : $this->input('currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return (new Account)->validationRules();
    }
}
