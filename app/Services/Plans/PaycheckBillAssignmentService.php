<?php

namespace App\Services\Plans;

use App\Models\BankTransaction;
use App\Models\PlannedTemplate;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PaycheckBillAssignmentService
{
    /**
     * @param  Collection<int, PlannedTemplate>|null  $bills
     */
    public function leftover(PlannedTemplate $paycheck, ?Collection $bills = null): float
    {
        $bills ??= $paycheck->assignedBills;

        $billsAmount = round(
            (float) $bills
                ->filter(fn (PlannedTemplate $bill) => $bill->is_active)
                ->sum(fn (PlannedTemplate $bill) => (float) $bill->expected_amount),
            2,
        );

        return round((float) $paycheck->expected_amount - $billsAmount, 2);
    }

    /**
     * @param  list<int>  $billTemplateIds
     */
    public function sync(PlannedTemplate $paycheck, array $billTemplateIds): void
    {
        if ($paycheck->classification !== BankTransaction::CLASSIFICATION_INCOME) {
            throw new NotFoundHttpException;
        }

        $paycheck->assignedBills()->sync($billTemplateIds);
    }
}
