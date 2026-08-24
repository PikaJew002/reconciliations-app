<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\ReimbursementGroup;
use App\Models\TransactionTransferLink;
use App\Models\VenmoActivity;

class ReconciliationReviewService
{
    public function __construct(
        protected OrderPaymentResolutionService $paymentResolution,
        protected ReimbursementGroupService $reimbursementGroups,
        protected VenmoActivityMatcher $venmoMatcher,
        protected TransactionCategorizationService $transactionCategorization,
        protected int $listLimit = 50,
        protected int $unmatchedTransactionsLimit = 250,
    ) {}

    /**
     * @param  array{
     *     unbalancedOrders?: list<array<string, mixed>>,
     *     paymentReviewOrders?: list<array<string, mixed>>,
     *     openReimbursementGroups?: list<array<string, mixed>>
     * }|null  $needsReviewPayload
     * @return array<string, int>
     */
    public function summaryForUser(int $userId, ?array $needsReviewPayload = null): array
    {
        $this->transactionCategorization->ignoreZeroAmountForUser($userId);

        $unbalancedOrders = $needsReviewPayload['unbalancedOrders']
            ?? $this->unbalancedOrders($userId);
        $paymentReviewOrders = $needsReviewPayload['paymentReviewOrders']
            ?? $this->paymentReviewOrders($userId);

        $suggestedTransfersCount = TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->where('status', TransactionTransferLink::STATUS_SUGGESTED)
            ->count();

        $openReimbursementGroupsCount = isset($needsReviewPayload['openReimbursementGroups'])
            ? count($needsReviewPayload['openReimbursementGroups'])
            : ReimbursementGroup::query()
                ->where('user_id', $userId)
                ->where('status', ReimbursementGroup::STATUS_OPEN)
                ->count();

        $suggestedVenmoCount = isset($needsReviewPayload['suggestedVenmoMatches'])
            ? count($needsReviewPayload['suggestedVenmoMatches'])
            : $this->bankFacingVenmoQuery($userId)
                ->where('match_status', VenmoActivity::STATUS_SUGGESTED)
                ->count();

        $unmatchedVenmoCount = isset($needsReviewPayload['unmatchedVenmoActivities'])
            ? count($needsReviewPayload['unmatchedVenmoActivities'])
            : $this->bankFacingVenmoQuery($userId)
                ->where('match_status', VenmoActivity::STATUS_UNMATCHED)
                ->count();

        $orderReviewCount = collect($unbalancedOrders)
            ->pluck('id')
            ->merge(collect($paymentReviewOrders)->pluck('id'))
            ->unique()
            ->count();

        return [
            'unmatched_orders' => Order::query()
                ->where('user_id', $userId)
                ->where('status', '!=', 'reconciled')
                ->count(),
            'reconciled_orders' => Order::query()
                ->where('user_id', $userId)
                ->where('status', 'reconciled')
                ->count(),
            'unmatched_transactions' => $this->unmatchedTransactionsQuery($userId)->count(),
            'unbalanced_orders' => count($unbalancedOrders),
            'payment_review_orders' => count($paymentReviewOrders),
            'suggested_transfers' => $suggestedTransfersCount,
            'open_reimbursement_groups' => $openReimbursementGroupsCount,
            'suggested_venmo_matches' => $suggestedVenmoCount,
            'unmatched_venmo_activities' => $unmatchedVenmoCount,
            'needs_review' => $orderReviewCount
                + $suggestedTransfersCount
                + $openReimbursementGroupsCount
                + $suggestedVenmoCount
                + $unmatchedVenmoCount,
        ];
    }

    /**
     * @return array{
     *     unbalancedOrders: list<array<string, mixed>>,
     *     paymentReviewOrders: list<array<string, mixed>>,
     *     suggestedTransfers: list<array<string, mixed>>,
     *     openReimbursementGroups: list<array<string, mixed>>,
     *     closedReimbursementGroups: list<array<string, mixed>>,
     *     reimbursementEligibleTransactions: list<array<string, mixed>>,
     *     suggestedVenmoMatches: list<array<string, mixed>>,
     *     unmatchedVenmoActivities: list<array<string, mixed>>
     * }
     */
    public function needsReviewForUser(int $userId): array
    {
        return [
            'unbalancedOrders' => $this->unbalancedOrders($userId),
            'paymentReviewOrders' => $this->paymentReviewOrders($userId),
            'suggestedTransfers' => $this->suggestedTransfers($userId),
            'openReimbursementGroups' => $this->reimbursementGroupsPayload($userId, ReimbursementGroup::STATUS_OPEN),
            'closedReimbursementGroups' => $this->reimbursementGroupsPayload($userId, ReimbursementGroup::STATUS_CLOSED),
            'reimbursementEligibleTransactions' => $this->reimbursementEligibleTransactions($userId),
            'suggestedVenmoMatches' => $this->venmoActivitiesPayload($userId, VenmoActivity::STATUS_SUGGESTED),
            'unmatchedVenmoActivities' => $this->venmoActivitiesPayload($userId, VenmoActivity::STATUS_UNMATCHED),
        ];
    }

    /**
     * @return array{
     *     unmatchedTransactions: list<array<string, mixed>>,
     *     openReimbursementGroups: list<array<string, mixed>>
     * }
     */
    public function unmatchedTransactionsForUser(int $userId): array
    {
        $this->transactionCategorization->ignoreZeroAmountForUser($userId);

        return [
            'unmatchedTransactions' => $this->unmatchedTransactions($userId),
            'openReimbursementGroups' => $this->reimbursementGroupsPayload($userId, ReimbursementGroup::STATUS_OPEN),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function reimbursementGroupsPayload(int $userId, string $status): array
    {
        return ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->where('status', $status)
            ->with([
                'remainderCategory:id,name,kind',
                'legs.bankTransaction.account:id,name,last_four',
                'legs.bankTransaction.merchant:id,name',
            ])
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(function (ReimbursementGroup $group): array {
                $expenseTotal = $group->expenseTotal();
                $reimbursementTotal = $group->reimbursementTotal();
                $net = $group->net();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'notes' => $group->notes,
                    'status' => $group->status,
                    'expense_total' => $expenseTotal,
                    'reimbursement_total' => $reimbursementTotal,
                    'net' => $net,
                    'remainder_category_id' => $group->remainder_category_id,
                    'remainder_classification' => $group->remainder_classification,
                    'remainder_category' => $group->remainderCategory?->name,
                    'closed_at' => $group->closed_at?->toDateTimeString(),
                    'legs' => $group->legs
                        ->sortBy(fn ($leg) => $leg->bankTransaction?->posted_at)
                        ->values()
                        ->map(function ($leg): array {
                            $transaction = $leg->bankTransaction;

                            return [
                                'id' => $leg->id,
                                'role' => $leg->role,
                                'amount' => (float) $leg->amount,
                                'transaction' => $transaction
                                    ? $this->transactionPayload($transaction, includeAccount: true)
                                    : null,
                            ];
                        })
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function reimbursementEligibleTransactions(int $userId): array
    {
        return $this->reimbursementGroups
            ->eligibleTransactionsForUser($userId)
            ->map(fn (BankTransaction $transaction): array => [
                ...$this->transactionPayload($transaction, includeAccount: true),
                'classification' => $transaction->classification,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unbalancedOrders(int $userId): array
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'reconciled')
            ->with([
                'merchant:id,name,normalized_name',
                'components' => fn ($query) => $query
                    ->with(['orderItem:id,quantity,unit_price,extended_price'])
                    ->withCount('allocations')
                    ->orderBy('id'),
            ])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Order $order): ?array {
                $componentSum = round((float) $order->components->sum('amount'), 2);
                $total = round((float) $order->total, 2);
                $gap = round($total - $componentSum, 2);

                if (abs($gap) < 0.01) {
                    return null;
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'ordered_at' => $order->ordered_at?->toDateString(),
                    'total' => $total,
                    'component_sum' => $componentSum,
                    'gap' => $gap,
                    'payment_last_four' => $order->payment_last_four,
                    'status' => $order->status,
                    'merchant' => $order->merchant?->name,
                    'components' => $order->components
                        ->map(function ($component): array {
                            $unallocated = (int) $component->allocations_count === 0;
                            $item = $component->orderItem;

                            return [
                                'id' => $component->id,
                                'type' => $component->type,
                                'description' => $component->description,
                                'amount' => (float) $component->amount,
                                'category_id' => $component->category_id,
                                'is_user_modified' => (bool) $component->is_user_modified,
                                'can_delete' => $unallocated,
                                'order_item_id' => $component->order_item_id,
                                'quantity' => $item !== null ? (float) $item->quantity : null,
                                'unit_price' => $item !== null ? (float) $item->unit_price : null,
                                'can_edit_quantity' => $item !== null && $unallocated,
                            ];
                        })
                        ->all(),
                ];
            })
            ->filter()
            ->take($this->listLimit)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function paymentReviewOrders(int $userId): array
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'reconciled')
            ->with(['merchant:id,name,normalized_name', 'components'])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Order $order): bool => $this->paymentResolution->needsPaymentReview($order))
            ->take($this->listLimit)
            ->values()
            ->map(function (Order $order): array {
                $payments = $this->paymentResolution->normalizedPayments($order);
                $componentSum = round((float) $order->components->sum('amount'), 2);
                $componentsBalanced = abs($componentSum - (float) $order->total) < 0.01;

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'ordered_at' => $order->ordered_at?->toDateString(),
                    'total' => (float) $order->total,
                    'payment_last_four' => $order->payment_last_four,
                    'status' => $order->status,
                    'merchant' => $order->merchant?->name,
                    'components_balanced' => $componentsBalanced,
                    'payments' => collect($payments)
                        ->values()
                        ->map(fn (array $payment, int $index): array => [
                            'index' => $index,
                            'ending' => $payment['ending'],
                            'last_four' => $payment['last_four'],
                            'amount' => $payment['amount'],
                            'kind' => $payment['kind'],
                            'requires_bank_transaction' => ! OrderPaymentResolutionService::isOffBookKind($payment['kind']),
                            'candidate_transactions' => $componentsBalanced
                                ? $this->paymentResolution->candidateTransactionsForPayment($order, $payment)
                                : [],
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unmatchedTransactions(int $userId): array
    {
        return $this->unmatchedTransactionsQuery($userId)
            ->with([
                'merchant:id,name,normalized_name,supports_order_import',
                'account:id,name,last_four,account_type,default_classification',
                'venmoActivities.cashedOutPayments',
            ])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($this->unmatchedTransactionsLimit)
            ->get()
            ->map(fn (BankTransaction $transaction): array => $this->transactionPayload($transaction, includeAccount: true))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function suggestedTransfers(int $userId): array
    {
        return TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->where('status', TransactionTransferLink::STATUS_SUGGESTED)
            ->with([
                'debitTransaction.account:id,name,last_four,account_type',
                'creditTransaction.account:id,name,last_four,account_type',
            ])
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(function (TransactionTransferLink $link): array {
                return [
                    'id' => $link->id,
                    'match_confidence' => $link->match_confidence !== null
                        ? (float) $link->match_confidence
                        : null,
                    'debit' => $this->transactionPayload($link->debitTransaction, includeAccount: true),
                    'credit' => $this->transactionPayload($link->creditTransaction, includeAccount: true),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function venmoActivitiesPayload(int $userId, string $matchStatus): array
    {
        return $this->bankFacingVenmoQuery($userId)
            ->where('match_status', $matchStatus)
            ->with([
                'bankTransaction.account:id,name,last_four,account_type',
                'cashedOutPayments',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(function (VenmoActivity $activity) use ($matchStatus): array {
                $candidates = $this->venmoMatcher->reviewCandidatesFor($activity);

                return [
                    'id' => $activity->id,
                    'occurred_at' => $activity->occurred_at?->toDateTimeString(),
                    'type' => $activity->type,
                    'note' => $activity->note,
                    'from_name' => $activity->from_name,
                    'to_name' => $activity->to_name,
                    'amount' => (float) $activity->amount,
                    'funding_last_four' => $activity->funding_last_four,
                    'destination_last_four' => $activity->destination_last_four,
                    'match_status' => $activity->match_status,
                    'label' => VenmoActivity::summarize(collect([$activity])),
                    'suggested_transaction' => $activity->bankTransaction
                        ? $this->transactionPayload($activity->bankTransaction, includeAccount: true)
                        : null,
                    'candidates' => $candidates
                        ->map(fn (BankTransaction $transaction): array => $this->transactionPayload($transaction, includeAccount: true))
                        ->values()
                        ->all(),
                    'can_confirm' => $matchStatus === VenmoActivity::STATUS_SUGGESTED
                        && $activity->bank_transaction_id !== null,
                ];
            })
            ->all();
    }

    protected function bankFacingVenmoQuery(int $userId)
    {
        return VenmoActivity::query()
            ->where('user_id', $userId)
            ->bankFacing();
    }

    protected function unmatchedTransactionsQuery(int $userId)
    {
        return BankTransaction::query()
            ->where('user_id', $userId)
            ->availableForExpenseMatching();
    }

    /**
     * @return array<string, mixed>
     */
    protected function transactionPayload(BankTransaction $transaction, bool $includeAccount = false): array
    {
        $isCredit = (float) $transaction->amount > 0;
        $isDebit = (float) $transaction->amount < 0;
        $canCategorizeBase = $transaction->status === 'unmatched'
            && $transaction->classification === null;

        $payload = [
            'id' => $transaction->id,
            'posted_at' => $transaction->posted_at?->toDateString(),
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'description' => $transaction->description,
            'amount' => (float) $transaction->amount,
            'card_last_four' => $transaction->card_last_four,
            'status' => $transaction->status,
            'merchant' => $transaction->merchant?->name,
            'supports_order_import' => (bool) ($transaction->merchant?->supports_order_import),
            'can_categorize' => $canCategorizeBase && ($isCredit || $isDebit),
            'one_off_categorize_only' => $isDebit
                && (bool) ($transaction->merchant?->supports_order_import),
            'venmo_summary' => $transaction->venmoSummary(),
        ];

        if ($includeAccount) {
            $payload['account_id'] = $transaction->account_id;
            $payload['account'] = $transaction->account?->name;
            $payload['account_last_four'] = $transaction->account?->last_four;
            $payload['account_default_classification'] = $isCredit
                ? BankTransaction::CLASSIFICATION_INCOME
                : ($transaction->account?->default_classification
                    ?? BankTransaction::CLASSIFICATION_EXPENSE);
        }

        return $payload;
    }
}
