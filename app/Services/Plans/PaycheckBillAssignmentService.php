<?php

namespace App\Services\Plans;

use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use Carbon\CarbonInterface;
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

    public function amountFor(?PlannedOccurrence $occurrence, float $fallback): float
    {
        if ($occurrence?->isResolved() && $occurrence->bankTransaction !== null) {
            return abs((float) $occurrence->bankTransaction->amount);
        }

        if ($occurrence !== null) {
            return (float) $occurrence->expected_amount;
        }

        return $fallback;
    }

    /**
     * @return array{
     *     paychecks: list<array<string, mixed>>,
     *     leftover: float,
     *     income: float,
     *     bills: float
     * }
     */
    public function monthCards(int $userId, CarbonInterface $monthStart): array
    {
        $monthStart = $monthStart->copy()->startOfMonth()->startOfDay();
        $monthEnd = $monthStart->copy()->addMonth();

        $paychecks = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('is_active', true)
            ->with('assignedBills')
            ->orderBy('expected_day')
            ->orderBy('name')
            ->get();

        $occurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('expected_date', '>=', $monthStart)
            ->where('expected_date', '<', $monthEnd)
            ->whereNotNull('template_id')
            ->with('bankTransaction:id,amount')
            ->get()
            ->keyBy(fn (PlannedOccurrence $occurrence) => (int) $occurrence->template_id);

        $cards = [];
        $totalIncome = 0.0;
        $totalBills = 0.0;

        foreach ($paychecks as $paycheck) {
            $paycheckOccurrence = $occurrences->get((int) $paycheck->id);
            $paycheckAmount = $this->amountFor(
                $paycheckOccurrence,
                (float) $paycheck->expected_amount,
            );
            $paycheckDate = $paycheckOccurrence?->expected_date
                ?? PlannedOccurrence::expectedDateForMonth($monthStart, (int) $paycheck->expected_day);

            $bills = [];
            $billsAmount = 0.0;

            foreach ($paycheck->assignedBills as $bill) {
                if (! $bill->is_active) {
                    continue;
                }

                $billOccurrence = $occurrences->get((int) $bill->id);
                $billAmount = $this->amountFor($billOccurrence, (float) $bill->expected_amount);
                $billDate = $billOccurrence?->expected_date
                    ?? PlannedOccurrence::expectedDateForMonth($monthStart, (int) $bill->expected_day);

                $bills[] = [
                    'id' => (int) $bill->id,
                    'name' => $bill->name,
                    'expected_day' => (int) $bill->expected_day,
                    'expected_date' => $billDate->toDateString(),
                    'amount' => $billAmount,
                    'status' => $billOccurrence?->status ?? PlannedOccurrence::STATUS_PLANNED,
                ];
                $billsAmount += $billAmount;
            }

            $billsAmount = round($billsAmount, 2);
            $leftover = round($paycheckAmount - $billsAmount, 2);

            $cards[] = [
                'id' => (int) $paycheck->id,
                'name' => $paycheck->name,
                'expected_day' => (int) $paycheck->expected_day,
                'expected_date' => $paycheckDate->toDateString(),
                'amount' => $paycheckAmount,
                'status' => $paycheckOccurrence?->status ?? PlannedOccurrence::STATUS_PLANNED,
                'bills' => $bills,
                'bills_amount' => $billsAmount,
                'leftover' => $leftover,
            ];

            $totalIncome += $paycheckAmount;
            $totalBills += $billsAmount;
        }

        return [
            'paychecks' => $cards,
            'income' => round($totalIncome, 2),
            'bills' => round($totalBills, 2),
            'leftover' => round($totalIncome - $totalBills, 2),
        ];
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
