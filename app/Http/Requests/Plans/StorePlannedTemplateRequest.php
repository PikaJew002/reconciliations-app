<?php

namespace App\Http\Requests\Plans;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePlannedTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $pattern = $this->input('normalized_pattern');

        if (is_string($pattern)) {
            $pattern = Str::of($pattern)->lower()->squish()->toString();
            $pattern = $pattern === '' ? null : $pattern;
        }

        $matchMode = $this->input('match_mode');
        $merchantId = $this->input('merchant_id');

        if (
            ($matchMode === null || $matchMode === '')
            && $merchantId
        ) {
            $matchMode = TransactionCategorizationRule::MATCH_MERCHANT;
        }

        $this->merge([
            'normalized_pattern' => $pattern,
            'match_mode' => $matchMode,
            'merchant_id' => $merchantId ?: null,
            'amount' => $this->filled('amount') ? $this->input('amount') : null,
            'lookback_days' => $this->input('lookback_days', 7),
            'lookforward_days' => $this->input('lookforward_days', 3),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'match_mode' => ['required', Rule::in(PlannedTemplate::incomeMatchModes())],
            'normalized_pattern' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_day' => ['required', 'integer', 'min:1', 'max:31'],
            'expected_amount' => ['required', 'numeric', 'min:0'],
            'lookback_days' => ['required', 'integer', 'min:0', 'max:31'],
            'lookforward_days' => ['required', 'integer', 'min:0', 'max:31'],
            'is_active' => ['sometimes', 'boolean'],
            'bills' => ['nullable', 'array'],
            'bills.*.category_id' => ['required', 'integer', 'exists:categories,id'],
            'bills.*.expected_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $userId = $this->user()?->id;

            if ($userId === null) {
                return;
            }

            $category = Category::query()->find($this->input('category_id'));

            if ($category === null || $category->user_id !== $userId || $category->kind !== Category::KIND_INCOME) {
                $validator->errors()->add('category_id', 'Choose an income category you own.');
            }

            $merchantId = $this->input('merchant_id');

            if ($merchantId) {
                $merchant = Merchant::query()->find($merchantId);

                if ($merchant === null || $merchant->user_id !== $userId) {
                    $validator->errors()->add('merchant_id', 'Choose a merchant you own.');
                }
            }

            $matchMode = $this->input('match_mode');
            $needsPattern = in_array($matchMode, [
                TransactionCategorizationRule::MATCH_DESCRIPTION,
                TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            ], true);
            $needsMerchant = in_array($matchMode, [
                TransactionCategorizationRule::MATCH_MERCHANT,
                TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT,
            ], true);
            $needsAmount = in_array($matchMode, [
                TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
                TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT,
            ], true);

            if ($needsPattern && ! $this->filled('normalized_pattern')) {
                $validator->errors()->add('normalized_pattern', 'A memo/description is required for this match mode.');
            }

            if ($needsMerchant && ! $this->filled('merchant_id')) {
                $validator->errors()->add('merchant_id', 'A merchant is required for this match mode.');
            }

            if ($needsAmount && ! $this->filled('amount')) {
                $validator->errors()->add('amount', 'An exact amount is required for this match mode.');
            }

            foreach ($this->input('bills', []) as $index => $bill) {
                $billCategoryId = (int) ($bill['category_id'] ?? 0);
                $billCategory = Category::query()->find($billCategoryId);

                if (
                    $billCategory === null
                    || $billCategory->user_id !== $userId
                    || $billCategory->kind !== Category::KIND_BILL
                ) {
                    $validator->errors()->add(
                        "bills.{$index}.category_id",
                        'Choose a bill category you own.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'category_id' => $validated['category_id'],
            'merchant_id' => $validated['merchant_id'] ?? null,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => $validated['match_mode'],
            'normalized_pattern' => $validated['normalized_pattern'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'expected_day' => $validated['expected_day'],
            'expected_amount' => $validated['expected_amount'],
            'lookback_days' => $validated['lookback_days'],
            'lookforward_days' => $validated['lookforward_days'],
            'is_active' => $validated['is_active'] ?? true,
        ];
    }

    /**
     * @return list<array{category_id: int, expected_amount: float}>
     */
    public function bills(): array
    {
        return collect($this->validated('bills') ?? [])
            ->map(fn (array $bill): array => [
                'category_id' => (int) $bill['category_id'],
                'expected_amount' => $bill['expected_amount'],
            ])
            ->values()
            ->all();
    }
}
