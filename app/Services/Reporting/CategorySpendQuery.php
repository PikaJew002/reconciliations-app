<?php

namespace App\Services\Reporting;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

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
 * - Ungrouped income with no category_id, plus closed over-reimbursed |net|, counts as uncategorized income.
 * - Open positive nets are exposed separately as awaiting reimbursement (not category spend).
 *
 * Optional $from / $to use a half-open window [from, to) on bank posted_at, order ordered_at,
 * and reimbursement group closed_at. Null bounds mean unbounded on that side (all-time when both null).
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
     * Ungrouped income credits with a category_id.
     *
     * @return array<int, float> category_id => income amount (positive)
     */
    public function incomeCategoryTotalsForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);

        $totals = [];

        $income = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('amount', '>', 0)
            ->whereNotNull('category_id')
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->get(['category_id', 'amount']);

        foreach ($income as $transaction) {
            $categoryId = (int) $transaction->category_id;
            $amount = (float) $transaction->amount;
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
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
        $groupedTransactionIds = $this->groupedTransactionIds($userId);

        $total = (float) BankTransaction::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->where('amount', '>', 0)
            ->whereNull('category_id')
            ->when(
                $groupedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
            )
            ->tap(fn (Builder $query) => $this->applyPostedAtRange($query, $from, $to))
            ->sum('amount');

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
     * plus closed over-reimbursement surplus booked as uncategorized income.
     */
    public function incomeTotalForUser(
        int $userId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): float {
        $categorized = array_sum($this->incomeCategoryTotalsForUser($userId, $from, $to));

        return round($categorized + $this->uncategorizedIncomeForUser($userId, $from, $to), 2);
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
