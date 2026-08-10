<?php

namespace App\Services\Reporting;

use App\Models\BankTransaction;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;

/**
 * Category spend contract for future reports.
 *
 * - Ungrouped categorized bank spend counts toward its category_id.
 * - Transactions in any reimbursement group are excluded from those raw totals.
 * - Closed groups contribute exactly their net to remainder_category_id (skip if ~0).
 * - Open group nets are exposed separately as awaiting reimbursement (not category spend).
 */
class CategorySpendQuery
{
    /**
     * @return array<int, float> category_id => spend amount (positive)
     */
    public function categoryTotalsForUser(int $userId): array
    {
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
            ->get(['category_id', 'amount']);

        foreach ($spend as $transaction) {
            $categoryId = (int) $transaction->category_id;
            $amount = abs((float) $transaction->amount);
            $totals[$categoryId] = round(($totals[$categoryId] ?? 0) + $amount, 2);
        }

        $closedGroups = ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->where('status', ReimbursementGroup::STATUS_CLOSED)
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

    public function awaitingReimbursementBalance(int $userId): float
    {
        return round(
            (float) ReimbursementGroup::query()
                ->where('user_id', $userId)
                ->where('status', ReimbursementGroup::STATUS_OPEN)
                ->with('legs')
                ->get()
                ->sum(fn (ReimbursementGroup $group): float => $group->net()),
            2,
        );
    }

    /**
     * Credits classified as income that are not in a reimbursement group.
     */
    public function incomeTotalForUser(int $userId): float
    {
        $groupedTransactionIds = $this->groupedTransactionIds($userId);

        return round(
            (float) BankTransaction::query()
                ->where('user_id', $userId)
                ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
                ->where('amount', '>', 0)
                ->when(
                    $groupedTransactionIds !== [],
                    fn ($query) => $query->whereNotIn('id', $groupedTransactionIds),
                )
                ->sum('amount'),
            2,
        );
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
