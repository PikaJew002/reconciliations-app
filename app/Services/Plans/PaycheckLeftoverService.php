<?php

namespace App\Services\Plans;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionTransferLink;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PaycheckLeftoverService
{
    public const ALLOCATION_CREDIT_CARD_PAYMENT = 'credit_card_payment';

    public const ALLOCATION_SAVINGS_TRANSFER = 'savings_transfer';

    public function __construct(
        protected PaycheckBillAssignmentService $assignments,
        protected CategorySpendQuery $spendQuery,
        protected PlannedOccurrenceGenerator $generator,
        protected LeftoverOriginService $origin,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function windows(int $userId): array
    {
        $this->generator->ensureForUser($userId);

        $paychecks = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->with('assignedBills')
            ->get()
            ->keyBy('id');

        if ($paychecks->isEmpty()) {
            return [];
        }

        $occurrences = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('template_id')
            ->with('bankTransaction:id,amount,posted_at')
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();

        $occurrencesByTemplateId = $occurrences->groupBy(
            fn (PlannedOccurrence $occurrence) => (int) $occurrence->template_id,
        );

        $incomeOccurrences = $occurrences
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->filter(fn (PlannedOccurrence $occurrence) => $paychecks->has($occurrence->template_id))
            ->values();

        if ($incomeOccurrences->isEmpty()) {
            return [];
        }

        $starts = $incomeOccurrences
            ->map(fn (PlannedOccurrence $occurrence) => [
                'occurrence' => $occurrence,
                'start' => $this->windowStart($occurrence),
            ])
            ->sort(function (array $left, array $right): int {
                $dateCompare = $left['start']->timestamp <=> $right['start']->timestamp;

                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return (int) $left['occurrence']->id <=> (int) $right['occurrence']->id;
            })
            ->values();

        $startsOn = $this->origin->ensureForUser($userId);

        if ($startsOn !== null) {
            $starts = $starts
                ->filter(fn (array $item) => ! $item['occurrence']->expected_date
                    ->copy()
                    ->startOfDay()
                    ->lt($startsOn))
                ->values();
        }

        if ($starts->isEmpty()) {
            return [];
        }

        $assignedBillTemplateIds = $paychecks
            ->flatMap(fn (PlannedTemplate $paycheck) => $paycheck->assignedBills->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $assignedBillTransactionIds = $occurrences
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->filter(fn (PlannedOccurrence $occurrence) => in_array(
                (int) $occurrence->template_id,
                $assignedBillTemplateIds,
                true,
            ))
            ->pluck('bank_transaction_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $from = $starts->first()['start'];
        $allocationEvents = $this->allocationEventsForUser($userId, $from);
        $spendEvents = collect($this->spendQuery->spendEventsForUser($userId, $from))
            ->reject(function (array $event) use ($assignedBillTransactionIds): bool {
                $transactionId = $event['bank_transaction_id'] ?? null;

                return $transactionId !== null
                    && in_array((int) $transactionId, $assignedBillTransactionIds, true);
            })
            ->values();

        $unassignedBillOccurrences = $occurrences
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->filter(fn (PlannedOccurrence $occurrence) => ! in_array(
                (int) $occurrence->template_id,
                $assignedBillTemplateIds,
                true,
            ))
            ->values();

        $billTemplates = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->get()
            ->keyBy('id');

        $windows = [];
        $broughtForward = 0.0;

        foreach ($starts as $index => $item) {
            /** @var PlannedOccurrence $occurrence */
            $occurrence = $item['occurrence'];
            $start = $item['start'];
            $next = $starts->get($index + 1);
            $end = $next['start'] ?? null;
            $paycheck = $paychecks->get($occurrence->template_id);
            $contribution = $this->assignments->contributionForPaycheck(
                $paycheck,
                $occurrence,
                $occurrence->expected_date,
                $occurrencesByTemplateId,
            );

            $windowEvents = $spendEvents->filter(
                fn (array $event) => $this->dateInWindow($event['date'], $start, $end),
            );

            $plannedUnassigned = $unassignedBillOccurrences->filter(function (PlannedOccurrence $bill) use ($start, $end): bool {
                return $bill->isPlanned()
                    && $this->dateInWindow($bill->expected_date->toDateString(), $start, $end);
            });

            $windowAllocations = $allocationEvents->filter(
                fn (array $event) => $this->dateInWindow($event['date'], $start, $end),
            )->values();

            $spent = round(
                (float) $windowEvents->sum('amount')
                + (float) $plannedUnassigned->sum(fn (PlannedOccurrence $bill) => (float) $bill->expected_amount),
                2,
            );
            $allocated = round((float) $windowAllocations->sum('amount'), 2);
            $creditCardPayments = round(
                (float) $windowAllocations
                    ->where('kind', self::ALLOCATION_CREDIT_CARD_PAYMENT)
                    ->sum('amount'),
                2,
            );
            $savingsTransfers = round(
                (float) $windowAllocations
                    ->where('kind', self::ALLOCATION_SAVINGS_TRANSFER)
                    ->sum('amount'),
                2,
            );

            $paycheckRemaining = round($contribution['leftover'] - $spent - $allocated, 2);
            $remaining = round($broughtForward + $paycheckRemaining, 2);
            $nextPaycheck = $next !== null
                ? $this->paycheckPayload($paychecks->get($next['occurrence']->template_id), $next['occurrence'], $next['start'])
                : null;

            $windows[] = [
                'paycheck' => $this->paycheckPayload($paycheck, $occurrence, $start),
                'next_paycheck' => $nextPaycheck,
                'starts_on' => $start->toDateString(),
                'ends_before' => $end?->toDateString(),
                'brought_forward' => $broughtForward,
                'planned_leftover' => $contribution['leftover'],
                'spent' => $spent,
                'allocated' => $allocated,
                'credit_card_payments' => $creditCardPayments,
                'savings_transfers' => $savingsTransfers,
                'allocations' => $windowAllocations->all(),
                'paycheck_remaining' => $paycheckRemaining,
                'remaining' => $remaining,
                ...$this->dayCounts($start, $end),
                'bills' => array_map(function (array $bill): array {
                    unset($bill['occurrence_id'], $bill['bank_transaction_id']);

                    return $bill;
                }, $contribution['bills']),
                'unassigned_bills' => $this->unassignedBillsPayload(
                    $windowEvents,
                    $plannedUnassigned,
                    $billTemplates,
                ),
            ];

            $broughtForward = $remaining;
        }

        return $windows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(int $userId, ?CarbonInterface $today = null): ?array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();
        $windows = $this->windows($userId);

        if ($windows === []) {
            return null;
        }

        foreach ($windows as $index => $window) {
            if ($this->containsDate($window, $today)) {
                return $this->withPreviousWindow($windows, $index);
            }
        }

        foreach ($windows as $index => $window) {
            if ($today->lt(Carbon::parse($window['starts_on'])->startOfDay())) {
                return $this->withPreviousWindow($windows, $index);
            }
        }

        return $this->withPreviousWindow($windows, (int) array_key_last($windows));
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return array<string, mixed>
     */
    protected function withPreviousWindow(array $windows, int $index): array
    {
        $window = $windows[$index];
        $previous = $index > 0 ? ($windows[$index - 1] ?? null) : null;

        $window['previous_paycheck'] = $previous['paycheck'] ?? null;
        $window['previous_paycheck_remaining'] = $previous['paycheck_remaining'] ?? null;

        return $window;
    }

    /**
     * Credit card payments and checking↔savings transfers change remaining
     * leftover without counting as discretionary spend.
     *
     * Allocated is signed because remaining always subtracts it: checking →
     * savings is positive, savings → checking is negative. Debit rows are
     * always stored negative, so the sign comes from direction, not abs(debit).
     *
     * @return Collection<int, array{date: string, amount: float, kind: string, name: ?string}>
     */
    protected function allocationEventsForUser(int $userId, CarbonInterface $from): Collection
    {
        $links = TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                TransactionTransferLink::STATUS_CONFIRMED,
                TransactionTransferLink::STATUS_SUGGESTED,
            ])
            ->with([
                'debitTransaction.account',
                'creditTransaction.account',
            ])
            ->get();

        $events = [];

        foreach ($links as $link) {
            $debit = $link->debitTransaction;

            if ($debit?->posted_at === null) {
                continue;
            }

            $allocation = $this->allocationForLink($link);

            if ($allocation === null) {
                continue;
            }

            $events[] = [
                'date' => $debit->posted_at->toDateString(),
                'amount' => $allocation['amount'],
                'kind' => $allocation['kind'],
                'name' => $debit->description,
            ];
        }

        return collect($events)->filter(
            fn (array $event) => ! Carbon::parse($event['date'])->startOfDay()->lt($from->copy()->startOfDay()),
        )->values();
    }

    /**
     * @return array{kind: string, amount: float}|null
     */
    protected function allocationForLink(TransactionTransferLink $link): ?array
    {
        $magnitude = abs((float) ($link->debitTransaction?->amount ?? 0));

        if ($magnitude < 0.01) {
            return null;
        }

        if (($link->metadata['kind'] ?? null) === self::ALLOCATION_CREDIT_CARD_PAYMENT) {
            return [
                'kind' => self::ALLOCATION_CREDIT_CARD_PAYMENT,
                'amount' => $magnitude,
            ];
        }

        $debitType = $link->debitTransaction?->account?->account_type;
        $creditType = $link->creditTransaction?->account?->account_type;

        if ($debitType === Account::CHECKING && $creditType === Account::SAVINGS) {
            return [
                'kind' => self::ALLOCATION_SAVINGS_TRANSFER,
                'amount' => $magnitude,
            ];
        }

        if ($debitType === Account::SAVINGS && $creditType === Account::CHECKING) {
            return [
                'kind' => self::ALLOCATION_SAVINGS_TRANSFER,
                'amount' => -1 * $magnitude,
            ];
        }

        return null;
    }

    protected function windowStart(PlannedOccurrence $occurrence): CarbonInterface
    {
        if ($occurrence->isResolved() && $occurrence->bankTransaction?->posted_at !== null) {
            return $occurrence->bankTransaction->posted_at->copy()->startOfDay();
        }

        return $occurrence->expected_date->copy()->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    protected function paycheckPayload(
        PlannedTemplate $paycheck,
        PlannedOccurrence $occurrence,
        CarbonInterface $date,
    ): array {
        return [
            'id' => (int) $paycheck->id,
            'occurrence_id' => (int) $occurrence->id,
            'name' => $paycheck->name,
            'date' => $date->toDateString(),
            'amount' => $this->assignments->amountFor($occurrence, (float) $paycheck->expected_amount),
            'status' => $occurrence->status,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $windowEvents
     * @param  Collection<int, PlannedOccurrence>  $plannedUnassigned
     * @param  Collection<int, PlannedTemplate>  $billTemplates
     * @return list<array<string, mixed>>
     */
    protected function unassignedBillsPayload(
        Collection $windowEvents,
        Collection $plannedUnassigned,
        Collection $billTemplates,
    ): array {
        $bills = [];

        foreach ($plannedUnassigned as $occurrence) {
            $template = $billTemplates->get($occurrence->template_id);

            $bills[] = [
                'id' => $occurrence->template_id !== null ? (int) $occurrence->template_id : null,
                'name' => $template?->name ?? $occurrence->normalized_pattern,
                'amount' => round((float) $occurrence->expected_amount, 2),
                'date' => $occurrence->expected_date->toDateString(),
                'status' => $occurrence->status,
            ];
        }

        foreach ($windowEvents as $event) {
            if ($event['classification'] !== BankTransaction::CLASSIFICATION_BILL) {
                continue;
            }

            $bills[] = [
                'id' => null,
                'name' => $event['name'] ?: 'Unplanned bill',
                'amount' => round((float) $event['amount'], 2),
                'date' => $event['date'],
                'status' => 'posted',
            ];
        }

        return $bills;
    }

    /**
     * @return array{days_elapsed: int, days_remaining: ?int, days_total: ?int}
     */
    protected function dayCounts(CarbonInterface $start, ?CarbonInterface $end): array
    {
        $today = Carbon::now()->startOfDay();
        $daysTotal = $end !== null ? $start->diffInDays($end) : null;

        if ($today->lt($start)) {
            $daysElapsed = 0;
        } elseif ($end !== null && $today->gte($end)) {
            $daysElapsed = $daysTotal ?? 0;
        } else {
            $daysElapsed = $start->diffInDays($today);
        }

        $daysRemaining = null;

        if ($end !== null) {
            $daysRemaining = (int) max(0, $today->diffInDays($end, false));
        }

        return [
            'days_elapsed' => (int) $daysElapsed,
            'days_remaining' => $daysRemaining,
            'days_total' => $daysTotal !== null ? (int) $daysTotal : null,
        ];
    }

    protected function dateInWindow(string $date, CarbonInterface $start, ?CarbonInterface $end): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        if ($day->lt($start)) {
            return false;
        }

        if ($end !== null && $day->gte($end)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $window
     */
    protected function containsDate(array $window, CarbonInterface $today): bool
    {
        $start = Carbon::parse($window['starts_on'])->startOfDay();

        if ($today->lt($start)) {
            return false;
        }

        if ($window['ends_before'] === null) {
            return true;
        }

        return $today->lt(Carbon::parse($window['ends_before'])->startOfDay());
    }
}
