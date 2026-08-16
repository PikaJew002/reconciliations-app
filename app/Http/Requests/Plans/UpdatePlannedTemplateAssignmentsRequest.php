<?php

namespace App\Http\Requests\Plans;

use App\Models\BankTransaction;
use App\Models\PlannedTemplate;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlannedTemplateAssignmentsRequest extends FormRequest
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
            'bill_template_ids' => ['present', 'array'],
            'bill_template_ids.*' => ['integer', 'distinct'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $userId = $this->user()?->id;
            $paycheck = $this->route('plannedTemplate');

            if ($userId === null || ! $paycheck instanceof PlannedTemplate) {
                return;
            }

            $ids = array_values(array_map(
                'intval',
                $this->input('bill_template_ids', []),
            ));

            if ($ids === []) {
                return;
            }

            $bills = PlannedTemplate::query()
                ->whereIn('id', $ids)
                ->with('assignedPaycheck')
                ->get()
                ->keyBy('id');

            if ($bills->count() !== count($ids)) {
                $validator->errors()->add('bill_template_ids', 'Choose bill plans you own.');

                return;
            }

            foreach ($ids as $id) {
                $bill = $bills->get($id);

                if (
                    $bill === null
                    || $bill->user_id !== $userId
                    || $bill->classification !== BankTransaction::CLASSIFICATION_BILL
                ) {
                    $validator->errors()->add('bill_template_ids', 'Choose bill plans you own.');

                    return;
                }

                $assignedPaycheck = $bill->assignedPaycheck->first();

                if (
                    $assignedPaycheck !== null
                    && (int) $assignedPaycheck->id !== (int) $paycheck->id
                ) {
                    $validator->errors()->add(
                        'bill_template_ids',
                        $bill->name.' is already assigned to another paycheck.',
                    );
                }
            }
        });
    }

    /**
     * @return list<int>
     */
    public function billTemplateIds(): array
    {
        return array_values(array_map(
            'intval',
            $this->validated('bill_template_ids') ?? [],
        ));
    }
}
