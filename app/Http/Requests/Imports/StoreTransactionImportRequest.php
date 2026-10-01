<?php

namespace App\Http\Requests\Imports;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $transactions = $this->input('transactions');

        if (! is_array($transactions)) {
            return;
        }

        $nullable = [
            'category',
            'import_tag',
            'check_number',
            'categorized_by',
            'categorized_date',
        ];

        $this->merge([
            'transactions' => array_map(function (mixed $transaction) use ($nullable): mixed {
                if (! is_array($transaction)) {
                    return $transaction;
                }

                foreach ($transaction as $field => $value) {
                    if (is_string($value)) {
                        $transaction[$field] = trim($value);
                    }
                }

                foreach ($nullable as $field) {
                    if (array_key_exists($field, $transaction) && $transaction[$field] === '') {
                        $transaction[$field] = null;
                    }
                }

                if (array_key_exists('account_number', $transaction) && is_numeric($transaction['account_number']) && ! is_string($transaction['account_number'])) {
                    $transaction['account_number'] = (string) $transaction['account_number'];
                }

                if (array_key_exists('check_number', $transaction) && is_numeric($transaction['check_number']) && ! is_string($transaction['check_number'])) {
                    $transaction['check_number'] = (string) $transaction['check_number'];
                }

                return $transaction;
            }, $transactions),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1', 'max:2000'],
            'transactions.*' => ['required', 'array'],
            'transactions.*.date' => ['required', 'date'],
            'transactions.*.description' => ['required', 'string', 'max:1000'],
            'transactions.*.category' => ['nullable', 'string', 'max:255'],
            'transactions.*.amount' => ['required', $this->amountRule()],
            'transactions.*.account' => ['required', 'string', 'max:255'],
            'transactions.*.account_number' => ['required', 'string', 'regex:/^\d{1,32}$/'],
            'transactions.*.institution' => ['required', 'string', 'max:255'],
            'transactions.*.month' => ['required', 'date'],
            'transactions.*.week' => ['required', 'date'],
            'transactions.*.transaction_id' => ['required', 'string', 'distinct', 'regex:/^[A-Za-z0-9]+$/', 'max:255'],
            'transactions.*.account_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'transactions.*.import_tag' => ['nullable', 'string', 'max:255'],
            'transactions.*.check_number' => ['nullable', 'string', 'max:32'],
            'transactions.*.full_description' => ['required', 'string', 'max:1000'],
            'transactions.*.date_added' => ['required', 'date'],
            'transactions.*.category_hint' => ['required', 'string', 'regex:/^[A-Z0-9_]+(?:: [A-Z0-9_]+)?$/'],
            'transactions.*.categorized_by' => ['nullable', 'string', 'max:255'],
            'transactions.*.categorized_date' => ['nullable', 'date'],
            'transactions.*.source' => ['required', 'string', 'max:100'],
        ];
    }

    private function amountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_int($value) || is_float($value)) {
                if ($value == 0.0) {
                    $fail('The amount must not be zero.');
                }

                return;
            }

            if (! is_string($value) || preg_match('/^-?\$?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?$/', $value) !== 1) {
                $fail('The amount must be a number or a currency amount like -$278.04.');

                return;
            }

            $normalized = (float) str_replace([',', '$'], '', $value);

            if ($normalized == 0.0) {
                $fail('The amount must not be zero.');
            }
        };
    }
}
