<?php

namespace App\Services\Reporting;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Category spend contract for reports.
 *
 * - Ungrouped categorized bank spend counts toward its category_id.
 * - Ungrouped bill/expense spend with no category_id counts as uncategorized spend.
 * - Categorized order components count toward their category_id (separate query; merge at call site).
 * - Order components with no category_id count as uncategorized spend (signed amounts).
 * - Transactions in any reimbursement group are excluded from those raw totals.
 * - Closed under-reimbursed groups contribute their positive net to remainder_category_id.
 * - Ungrouped categorized income credits count toward their category_id.
 * - Income linked to a planned occurrence is attributed by the occurrence scheduled_date, not posted_at.
 * - Still-planned income occurrences count expected_amount toward their category_id.
 * - Ungrouped income with no category_id, plus closed over-reimbursed |net|, counts as uncategorized income.
 * - Open positive nets are exposed separately as awaiting reimbursement (not category spend).
 * - Unmatched pending spend (pending or needs_review) counts by spent_at as a stand-in.
 * - Resolved and cancelled pending spend do not count; posted bank spend is unchanged.
 *
 * Optional $from / $to use a half-open window [from, to) on unmatched bank posted_at, occurrence
 * scheduled_date, order ordered_at, reimbursement group closed_at, and pending spent_at.
 * Null bounds mean unbounded on that side (all-time when both null).
 */
class CategorySpendQuery
{
    /**
     * @return array<int, float> category_id => spend amount (positive)
     */
    public function categoryTotalsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);

        $totals = [];

        $spend = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('classification', [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ])
            ->whereNotNull('category_id')
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->get(['category_id', 'amount']);

        foreach ($spend as $transaction) {
            $categoryId = (int) $transaction->category_id;
            $amount = abs((float) $transaction->amount);
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
        }

        $closedGroups = ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->where('status', ReimbursementGroup::STATUS_CLOSED)
            ->tap(fn (Builder $query) => $this->applyClosedAtRange($query, $from, $to))
            ->with('legs')
            ->get();

        foreach ($closedGroups as $group) {
            $net = $group->net();

            if ($net < 0.01 || $group->remainder_category_id === null) {
                continue;
            }

            $categoryId = (int) $group->remainder_category_id;
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $net, 2);
        }

        foreach ($this->unmatchedPendingSpendsForUser($userId, $from, $to) as $pending) {
            if ($pending->category_id === null) {
                continue;
            }

            if (! in_array($pending->classification, [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ], true)) {
                continue;
            }

            $categoryId = (int) $pending->category_id;
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + (float) $pending->amount, 2);
        }

        return $totals;
    }

    /**
     * Ungrouped bill/expense spend with no category_id.
     *
     * @param  list<string>|null  $classifications
     */
    public function uncategorizedSpendForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?array $classifications = null,
    ): float {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);
        $classifications ??= [
            BankTransaction::CLASSIFICATION_BILL,
            BankTransaction::CLASSIFICATION_EXPENSE,
        ];

        $total = (float) BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('classification', $classifications)
            ->whereNull('category_id')
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->get(['amount'])
            ->sum(fn (BankTransaction $transaction): float => abs((float) $transaction->amount));

        foreach ($this->unmatchedPendingSpendsForUser($userId, $from, $to) as $pending) {
            if ($pending->category_id !== null) {
                continue;
            }

            if (! in_array($pending->classification, $classifications, true)) {
                continue;
            }

            $total += (float) $pending->amount;
        }

        return round($total, 2);
    }

    public function uncategorizedBillSpendForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        return $this->uncategorizedSpendForUser(
            $userId,
            $from,
            $to,
            [BankTransaction::CLASSIFICATION_BILL],
        );
    }

    public function uncategorizedExpenseSpendForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        return $this->uncategorizedSpendForUser(
            $userId,
            $from,
            $to,
            [BankTransaction::CLASSIFICATION_EXPENSE],
        );
    }

    /**
     * Categorized order-component spend for the user.
     *
     * @return array<int, float> category_id => spend amount (signed; discounts may be negative)
     */
    public function orderComponentCategoryTotalsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $orderIds = $this->orderIdsForUser($userId, $from, $to);

        if ($orderIds === []) {
            return [];
        }

        $totals = [];

        $components = OrderComponent::query()
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('category_id')
            ->get(['category_id', 'amount']);

        foreach ($components as $component) {
            $categoryId = (int) $component->category_id;
            $amount = (float) $component->amount;
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
        }

        return $totals;
    }

    /**
     * Order-component spend with no category_id (signed; discounts may be negative).
     */
    public function orderComponentUncategorizedSpendForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        $orderIds = $this->orderIdsForUser($userId, $from, $to);

        if ($orderIds === []) {
            return 0.0;
        }

        $total = (float) OrderComponent::query()
            ->whereIn('order_id', $orderIds)
            ->whereNull('category_id')
            ->sum('amount');

        return round($total, 2);
    }

    /**
     * Date window for spend totals: ungrouped bill/expense bank posted_at
     * and order ordered_at.
     *
     * @return array{
     *     from: ?string,
     *     to: ?string,
     *     bank_from: ?string,
     *     bank_to: ?string,
     *     orders_from: ?string,
     *     orders_to: ?string
     * }
     */
    public function spendCoverageForUser(int $userId): array
    {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);

        $bankCoverage = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('classification', [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ])
            ->whereNotNull('posted_at')
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->selectRaw('MIN(posted_at) as min_posted_at, MAX(posted_at) as max_posted_at')
            ->first();

        $bankFrom = $this->toDateString($bankCoverage?->min_posted_at);
        $bankTo = $this->toDateString($bankCoverage?->max_posted_at);

        $orderCoverage = Order::query()
            ->where('user_id', $userId)
            ->whereNotNull('ordered_at')
            ->selectRaw('MIN(ordered_at) as min_ordered_at, MAX(ordered_at) as max_ordered_at')
            ->first();

        $ordersFrom = $this->toDateString($orderCoverage?->min_ordered_at);
        $ordersTo = $this->toDateString($orderCoverage?->max_ordered_at);

        $bounds = array_values(array_filter(
            [$bankFrom, $bankTo, $ordersFrom, $ordersTo],
            fn (?string $date): bool => $date !== null,
        ));

        sort($bounds);

        return [
            'from' => $bounds[0] ?? null,
            'to' => $bounds !== [] ? $bounds[array_key_last($bounds)] : null,
            'bank_from' => $bankFrom,
            'bank_to' => $bankTo,
            'orders_from' => $ordersFrom,
            'orders_to' => $ordersTo,
        ];
    }

    /**
     * @return list<int>
     */
    protected function orderIdsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        return Order::query()
            ->where('user_id', $userId)
            ->tap(fn (Builder $query) => $this->applyOrderedAtRange($query, $from, $to))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    /**
     * Dated spend events for leftover window bucketing. Same sources as
     * category totals, including unmatched pending spend, with a date on each
     * row so callers can split one query across paycheck windows.
     *
     * @return list<array{
     *     date: string,
     *     amount: float,
     *     classification: string,
     *     source: string,
     *     bank_transaction_id: ?int,
     *     pending_spend_id: ?int,
     *     order_id: ?int,
     *     order_component_id: ?int,
     *     component_type: ?string,
     *     reimbursement_group_id: ?int,
     *     name: ?string,
     *     category_id: ?int
     * }>
     */
    public function spendEventsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);
        $events = [];

        $spend = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('classification', [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ])
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->get(['id', 'posted_at', 'amount', 'classification', 'description', 'category_id']);

        foreach ($spend as $transaction) {
            if ($transaction->posted_at === null) {
                continue;
            }

            $events[] = [
                'date' => $transaction->posted_at->toDateString(),
                'amount' => abs((float) $transaction->amount),
                'classification' => $transaction->classification,
                'source' => 'bank',
                'bank_transaction_id' => (int) $transaction->id,
                'pending_spend_id' => null,
                'order_id' => null,
                'order_component_id' => null,
                'reimbursement_group_id' => null,
                'name' => $transaction->description,
                'category_id' => $transaction->category_id !== null ? (int) $transaction->category_id : null,
            ];
        }

        foreach ($this->unmatchedPendingSpendsForUser($userId, $from, $to) as $pending) {
            if ($pending->spent_at === null) {
                continue;
            }

            if (! in_array($pending->classification, [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ], true)) {
                continue;
            }

            $events[] = [
                'date' => $pending->spent_at->toDateString(),
                'amount' => round((float) $pending->amount, 2),
                'classification' => $pending->classification,
                'source' => 'pending',
                'bank_transaction_id' => null,
                'pending_spend_id' => (int) $pending->id,
                'order_id' => null,
                'order_component_id' => null,
                'reimbursement_group_id' => null,
                'name' => $pending->notes,
                'category_id' => $pending->category_id !== null ? (int) $pending->category_id : null,
            ];
        }

        $orders = Order::query()
            ->where('user_id', $userId)
            ->tap(fn (Builder $query) => $this->applyOrderedAtRange($query, $from, $to))
            ->with(['components:id,order_id,amount,category_id,description,type'])
            ->get(['id', 'ordered_at']);

        foreach ($orders as $order) {
            if ($order->ordered_at === null) {
                continue;
            }

            foreach ($order->components as $component) {
                $events[] = [
                    'date' => $order->ordered_at->toDateString(),
                    'amount' => round((float) $component->amount, 2),
                    'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                    'source' => 'order_component',
                    'bank_transaction_id' => null,
                    'pending_spend_id' => null,
                    'order_id' => (int) $order->id,
                    'order_component_id' => (int) $component->id,
                    'component_type' => $component->type,
                    'reimbursement_group_id' => null,
                    'name' => $component->description,
                    'category_id' => $component->category_id !== null ? (int) $component->category_id : null,
                ];
            }
        }

        $closedGroups = ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->where('status', ReimbursementGroup::STATUS_CLOSED)
            ->tap(fn (Builder $query) => $this->applyClosedAtRange($query, $from, $to))
            ->with('legs')
            ->get();

        foreach ($closedGroups as $group) {
            $net = $group->net();

            if ($net < 0.01) {
                continue;
            }

            if (! in_array($group->remainder_classification, [
                BankTransaction::CLASSIFICATION_BILL,
                BankTransaction::CLASSIFICATION_EXPENSE,
            ], true)) {
                continue;
            }

            if ($group->closed_at === null) {
                continue;
            }

            $events[] = [
                'date' => $group->closed_at->toDateString(),
                'amount' => round($net, 2),
                'classification' => $group->remainder_classification,
                'source' => 'reimbursement',
                'bank_transaction_id' => null,
                'pending_spend_id' => null,
                'order_id' => null,
                'order_component_id' => null,
                'reimbursement_group_id' => (int) $group->id,
                'name' => $group->name,
                'category_id' => $group->remainder_category_id !== null
                    ? (int) $group->remainder_category_id
                    : null,
            ];
        }

        return $events;
    }

    public function awaitingReimbursementBalance(int $userId): float
    {
        return round(
            (float) ReimbursementGroup::query()
                ->where('user_id', $userId)
                ->where('status', ReimbursementGroup::STATUS_OPEN)
                ->with('legs')
                ->get()
                ->sum(fn (ReimbursementGroup $group): float => max($group->net(), 0)),
            2,
        );
    }

    /**
     * Ungrouped income credits with a category_id, plus planned/resolved
     * occurrence amounts attributed by scheduled_date.
     *
     * @return array<int, float> category_id => income amount (positive)
     */
    public function incomeCategoryTotalsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $excludedTransactionIds = $this->incomeExcludedTransactionIds($userId);
        $totals = [];

        $income = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('amount', '>', 0)
            ->whereNotNull('category_id')
            ->when(
                $excludedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->get(['category_id', 'amount']);

        foreach ($income as $transaction) {
            $this->addIncomeTotal(
                $totals,
                (int) $transaction->category_id,
                (float) $transaction->amount,
            );
        }

        foreach ($this->incomeOccurrencesForUser($userId, $from, $to) as $occurrence) {
            if ($occurrence->isResolved()) {
                $transaction = $occurrence->bankTransaction;
                $amount = $transaction !== null ? (float) $transaction->amount : 0.0;
                $categoryId = $transaction?->category_id ?? $occurrence->category_id;
            } else {
                $amount = (float) $occurrence->expected_amount;
                $categoryId = $occurrence->category_id;
            }

            if ($categoryId === null || $amount <= 0) {
                continue;
            }

            $this->addIncomeTotal($totals, (int) $categoryId, $amount);
        }

        return $totals;
    }

    /**
     * Ungrouped income with no category_id, plus closed over-reimbursement
     * surplus booked as uncategorized income.
     */
    public function uncategorizedIncomeForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        $excludedTransactionIds = $this->incomeExcludedTransactionIds($userId);

        $total = (float) BankTransaction::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('amount', '>', 0)
            ->whereNull('category_id')
            ->when(
                $excludedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->sum('amount');

        foreach ($this->incomeOccurrencesForUser($userId, $from, $to) as $occurrence) {
            if ($occurrence->isResolved()) {
                $transaction = $occurrence->bankTransaction;

                if ($transaction === null || $transaction->category_id !== null) {
                    continue;
                }

                $total += (float) $transaction->amount;

                continue;
            }

            if ($occurrence->category_id !== null) {
                continue;
            }

            $total += (float) $occurrence->expected_amount;
        }

        $closedGroups = ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->where('status', ReimbursementGroup::STATUS_CLOSED)
            ->where('remainder_classification', BankTransaction::CLASSIFICATION_INCOME)
            ->tap(fn (Builder $query) => $this->applyClosedAtRange($query, $from, $to))
            ->with('legs')
            ->get();

        foreach ($closedGroups as $group) {
            $net = $group->net();

            if ($net > -0.01) {
                continue;
            }

            $total += abs($net);
        }

        return round($total, 2);
    }

    /**
     * Credits classified as income that are not in a reimbursement group,
     * plus still-planned occurrence amounts and closed over-reimbursement surplus.
     */
    public function incomeTotalForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        $categorized = array_sum($this->incomeCategoryTotalsForUser($userId, $from, $to));

        return round($categorized + $this->uncategorizedIncomeForUser($userId, $from, $to), 2);
    }

    /**
     * @return array{received: float, planned: float, total: float}
     */
    public function incomeBreakdownForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $total = $this->incomeTotalForUser($userId, $from, $to);
        $planned = 0.0;

        foreach ($this->incomeOccurrencesForUser($userId, $from, $to) as $occurrence) {
            if ($occurrence->isPlanned()) {
                $planned = round($planned + (float) $occurrence->expected_amount, 2);
            }
        }

        $received = round($total - $planned, 2);

        return [
            'received' => $received,
            'planned' => $planned,
            'total' => $total,
        ];
    }

    protected function applyPostedAtRange(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): void {
        if ($from !== null) {
            $query->where('posted_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('posted_at', '<', $to);
        }
    }

    protected function applyOrderedAtRange(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): void {
        if ($from !== null) {
            $query->where('ordered_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('ordered_at', '<', $to);
        }
    }

    /**
     * Closed reimbursement remainder/surplus is attributed by closed_at.
     * When a date window is applied, groups with null closed_at are excluded.
     */
    protected function applyClosedAtRange(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): void {
        if ($from === null && $to === null) {
            return;
        }

        $query->whereNotNull('closed_at');

        if ($from !== null) {
            $query->where('closed_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('closed_at', '<', $to);
        }
    }

    /**
     * @param  array<int, float>  $totals
     */
    protected function addIncomeTotal(array &$totals, int $categoryId, float $amount): void
    {
        $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
    }

    /**
     * @return Collection<int, PlannedOccurrence>
     */
    protected function incomeOccurrencesForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ) {
        return PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where(function ($query) {
                $query->where('status', PlannedOccurrence::STATUS_RESOLVED)
                    ->orWhere(function ($plannedQuery) {
                        $plannedQuery->where('status', PlannedOccurrence::STATUS_PLANNED)
                            ->where(function ($templateQuery) {
                                $templateQuery->whereNull('template_id')
                                    ->orWhereHas(
                                        'template',
                                        fn ($active) => $active->where('is_active', true),
                                    );
                            });
                    });
            })
            ->tap(fn (Builder $query) => $this->applyScheduledDateRange($query, $from, $to))
            ->with('bankTransaction')
            ->get();
    }

    /**
     * @return list<int>
     */
    protected function incomeExcludedTransactionIds(int $userId): array
    {
        return array_values(array_unique([
            ...$this->groupedTransactionIds($userId),
            ...$this->plannedOccurrenceTransactionIds($userId),
        ]));
    }

    /**
     * @return list<int>
     */
    protected function plannedOccurrenceTransactionIds(int $userId): array
    {
        return PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function applyScheduledDateRange(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): void {
        if ($from !== null) {
            $query->where('scheduled_date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('scheduled_date', '<', $to);
        }
    }

    /**
     * @return Collection<int, PendingSpend>
     */
    protected function unmatchedPendingSpendsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ) {
        return PendingSpend::query()
            ->where('user_id', $userId)
            ->whereIn('status', PendingSpend::unmatchedStatuses())
            ->tap(fn (Builder $query) => $this->applySpentAtRange($query, $from, $to))
            ->get();
    }

    protected function applySpentAtRange(
        Builder $query,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): void {
        if ($from !== null) {
            $query->where('spent_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('spent_at', '<', $to);
        }
    }

    /**
     * @return list<int>
     */
    protected function groupedTransactionIds(int $userId): array
    {
        return ReimbursementGroupTransaction::query()
            ->whereHas('group', fn ($query) => $query->where('user_id', $userId))
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
