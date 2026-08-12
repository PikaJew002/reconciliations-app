<?php

namespace App\Http\Requests\Budgets;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBudgetRequest extends FormRequest
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
            'limits' => ['nullable', 'array'],
            'limits.*.category_id' => ['required', 'integer'],
            'limits.*.amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $limits = $this->input('limits', []);

            if (! is_array($limits) || $limits === []) {
                return;
            }

            $categoryIds = collect($limits)
                ->pluck('category_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($categoryIds === []) {
                return;
            }

            $ownedExpenseIds = Category::query()
                ->where('user_id', $this->user()->id)
                ->where('kind', Category::KIND_EXPENSE)
                ->whereIn('id', $categoryIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ownedLookup = array_flip($ownedExpenseIds);

            foreach ($limits as $index => $limit) {
                $categoryId = (int) ($limit['category_id'] ?? 0);

                if ($categoryId === 0) {
                    continue;
                }

                if (! isset($ownedLookup[$categoryId])) {
                    $validator->errors()->add(
                        "limits.{$index}.category_id",
                        'Budget limits may only be set on your expense categories.',
                    );
                }
            }
        });
    }

    /**
     * @return array<int, float|null> category_id => amount
     */
    public function limitsByCategoryId(): array
    {
        $mapped = [];

        foreach ($this->validated('limits') ?? [] as $limit) {
            $categoryId = (int) $limit['category_id'];
            $amount = $limit['amount'] ?? null;
            $mapped[$categoryId] = $amount === null || $amount === ''
                ? null
                : round((float) $amount, 2);
        }

        return $mapped;
    }
}
