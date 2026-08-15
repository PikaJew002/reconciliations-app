<?php

namespace App\Http\Requests\Plans;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlannedOccurrenceBillsRequest extends FormRequest
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
