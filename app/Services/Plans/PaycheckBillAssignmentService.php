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
     * A paycheck covers assigned bills from its expected day up to, but not
     * including, the next occurrence of that same paycheck. Bills due earlier
     * in the month are therefore the following month's occurrence.
     */
    public function billCoversNextMonth(PlannedTemplate $paycheck, PlannedTemplate $bill): bool
    {
        return (int) $bill->expected_day < (int) $paycheck->expected_day;
    }

    public function billCoverageMonth(
        PlannedTemplate $paycheck,
        PlannedTemplate $bill,
        CarbonInterface $paycheckMonth,
    ): CarbonInterface {
        $month = $paycheckMonth->copy()->startOfMonth()->startOfDay();

        return $this->billCoversNextMonth($paycheck, $bill)
            ? $month->addMonth()
            : $month;
    }

    /**
     * @param  Collection<int, Collection<int, PlannedOccurrence>>  $occurrencesByTemplateId
     * @return array{
     *     amount: float,
     *     date: CarbonInterface,
     *     status: string,
     *     bills: list<array<string, mixed>>,
     *     bills_amount: float,
     *     leftover: float
     * }
     */
    public function contributionForPaycheck(
        PlannedTemplate $paycheck,
        ?PlannedOccurrence $paycheckOccurrence,
        CarbonInterface $paycheckMonth,
        Collection $occurrencesByTemplateId,
    ): array {
        $paycheckMonth = $paycheckMonth->copy()->startOfMonth()->startOfDay();
        $paycheckAmount = $this->amountFor(
            $paycheckOccurrence,
            (float) $paycheck->expected_amount,
        );
        $paycheckDate = $paycheckOccurrence?->expected_date
            ?? PlannedOccurrence::expectedDateForMonth($paycheckMonth, (int) $paycheck->expected_day);

        $bills = [];
        $billsAmount = 0.0;

        foreach ($paycheck->assignedBills as $bill) {
            if (! $bill->is_active) {
                continue;
            }

            $coversNextMonth = $this->billCoversNextMonth($paycheck, $bill);
            $billMonth = $this->billCoverageMonth($paycheck, $bill, $paycheckMonth);
            $billOccurrence = $this->occurrenceInMonth(
                $occurrencesByTemplateId->get((int) $bill->id),
                $billMonth,
            );
            $billAmount = $this->amountFor($billOccurrence, (float) $bill->expected_amount);
            $billDate = $billOccurrence?->expected_date
                ?? PlannedOccurrence::expectedDateForMonth($billMonth, (int) $bill->expected_day);

            $bills[] = [
                'id' => (int) $bill->id,
                'occurrence_id' => $billOccurrence?->id,
                'name' => $bill->name,
                'expected_day' => (int) $bill->expected_day,
                'expected_date' => $billDate->toDateString(),
                'covers_next_month' => $coversNextMonth,
                'amount' => $billAmount,
                'status' => $billOccurrence?->status ?? PlannedOccurrence::STATUS_PLANNED,
                'bank_transaction_id' => $billOccurrence?->bank_transaction_id,
            ];
            $billsAmount += $billAmount;
        }

        $billsAmount = round($billsAmount, 2);

        return [
            'amount' => $paycheckAmount,
            'date' => $paycheckDate,
            'status' => $paycheckOccurrence?->status ?? PlannedOccurrence::STATUS_PLANNED,
            'bills' => $bills,
            'bills_amount' => $billsAmount,
            'leftover' => round($paycheckAmount - $billsAmount, 2),
        ];
    }

    /**
     * Current paycheck plus later paychecks through the end of next month.
     * Anchored to today, not a selected calendar month.
     *
     * @return array{
     *     paychecks: list<array<string, mixed>>,
     *     leftover: float,
     *     income: float,
     *     bills: float
     * }
     */
    public function upcomingCards(int $userId, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $horizonEnd = $today->copy()->addMonth()->endOfMonth()->startOfDay();
        $loadFrom = $today->copy()->startOfMonth()->subMonth()->startOfDay();
        $loadUntil = $horizonEnd->copy()->addMonth()->startOfDay();

        $paychecks = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('is_active', true)
            ->with('assignedBills')
            ->get()
            ->keyBy('id');

        if ($paychecks->isEmpty()) {
            return $this->emptyCards();
        }

        $occurrences = $this->occurrencesByTemplateId($userId, $loadFrom, $loadUntil);

        $incomeOccurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->whereIn('template_id', $paychecks->keys())
            ->where('scheduled_date', '>=', $loadFrom)
            ->where('scheduled_date', '<=', $horizonEnd)
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();

        $current = $incomeOccurrences
            ->filter(fn (PlannedOccurrence $occurrence) => ! $occurrence->expected_date->copy()->startOfDay()->gt($today))
            ->last();

        if ($current === null) {
            $current = $incomeOccurrences->first();
        }

        if ($current === null) {
            return $this->emptyCards();
        }

        $from = $current->expected_date->copy()->startOfDay();
        $cards = [];
        $totalIncome = 0.0;
        $totalBills = 0.0;

        foreach ($incomeOccurrences as $occurrence) {
            $date = $occurrence->expected_date->copy()->startOfDay();

            if ($date->lt($from) || $date->gt($horizonEnd)) {
                continue;
            }

            $paycheck = $paychecks->get((int) $occurrence->template_id);

            if ($paycheck === null) {
                continue;
            }

            $paycheckMonth = $occurrence->periodDate()->copy()->startOfMonth()->startOfDay();
            $contribution = $this->contributionForPaycheck(
                $paycheck,
                $occurrence,
                $paycheckMonth,
                $occurrences,
            );

            $cards[] = $this->cardPayload(
                $paycheck,
                $contribution,
                id: (int) $occurrence->id,
                extra: [
                    'template_id' => (int) $paycheck->id,
                    'is_current' => (int) $occurrence->id === (int) $current->id,
                ],
            );

            $totalIncome += $contribution['amount'];
            $totalBills += $contribution['bills_amount'];
        }

        return $this->cardsTotals($cards, $totalIncome, $totalBills);
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

        $paychecks = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('is_active', true)
            ->with('assignedBills')
            ->orderBy('expected_day')
            ->orderBy('name')
            ->get();

        $occurrences = $this->occurrencesByTemplateId(
            $userId,
            $monthStart,
            $monthStart->copy()->addMonths(2),
        );

        $cards = [];
        $totalIncome = 0.0;
        $totalBills = 0.0;

        foreach ($paychecks as $paycheck) {
            $paycheckOccurrence = $this->occurrenceInMonth(
                $occurrences->get((int) $paycheck->id),
                $monthStart,
            );
            $contribution = $this->contributionForPaycheck(
                $paycheck,
                $paycheckOccurrence,
                $monthStart,
                $occurrences,
            );

            $cards[] = $this->cardPayload($paycheck, $contribution, id: (int) $paycheck->id);

            $totalIncome += $contribution['amount'];
            $totalBills += $contribution['bills_amount'];
        }

        return $this->cardsTotals($cards, $totalIncome, $totalBills);
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

    /**
     * @return Collection<int, Collection<int, PlannedOccurrence>>
     */
    protected function occurrencesByTemplateId(
        int $userId,
        CarbonInterface $from,
        CarbonInterface $until,
    ): Collection {
        return PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('scheduled_date', '>=', $from)
            ->where('scheduled_date', '<', $until)
            ->whereNotNull('template_id')
            ->with('bankTransaction:id,amount')
            ->get()
            ->groupBy(fn (PlannedOccurrence $occurrence) => (int) $occurrence->template_id);
    }

    /**
     * @param  array{
     *     amount: float,
     *     date: CarbonInterface,
     *     status: string,
     *     bills: list<array<string, mixed>>,
     *     bills_amount: float,
     *     leftover: float
     * }  $contribution
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function cardPayload(
        PlannedTemplate $paycheck,
        array $contribution,
        int $id,
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'name' => $paycheck->name,
            'expected_day' => (int) $paycheck->expected_day,
            'expected_date' => $contribution['date']->toDateString(),
            'amount' => $contribution['amount'],
            'status' => $contribution['status'],
            'bills' => array_map(function (array $bill): array {
                unset($bill['occurrence_id'], $bill['bank_transaction_id']);

                return $bill;
            }, $contribution['bills']),
            'bills_amount' => $contribution['bills_amount'],
            'leftover' => $contribution['leftover'],
            ...$extra,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array{
     *     paychecks: list<array<string, mixed>>,
     *     leftover: float,
     *     income: float,
     *     bills: float
     * }
     */
    protected function cardsTotals(array $cards, float $totalIncome, float $totalBills): array
    {
        return [
            'paychecks' => $cards,
            'income' => round($totalIncome, 2),
            'bills' => round($totalBills, 2),
            'leftover' => round($totalIncome - $totalBills, 2),
        ];
    }

    /**
     * @return array{
     *     paychecks: list<array<string, mixed>>,
     *     leftover: float,
     *     income: float,
     *     bills: float
     * }
     */
    protected function emptyCards(): array
    {
        return $this->cardsTotals([], 0.0, 0.0);
    }

    /**
     * @param  Collection<int, PlannedOccurrence>|null  $occurrences
     */
    protected function occurrenceInMonth(?Collection $occurrences, CarbonInterface $month): ?PlannedOccurrence
    {
        if ($occurrences === null || $occurrences->isEmpty()) {
            return null;
        }

        return $occurrences->first(
            fn (PlannedOccurrence $occurrence) => $occurrence->belongsToMonth($month),
        );
    }
}
