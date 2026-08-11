<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\ReimbursementGroup;
use App\Models\TransactionAllocation;
use App\Models\TransactionTransferLink;
use Illuminate\Support\Facades\DB;

class ReconciliationReviewService
{
    public function __construct(
        protected OrderPaymentResolutionService $paymentResolution,
        protected ReimbursementGroupService $reimbursementGroups,
        protected int $listLimit = 50,
        protected int $unmatchedTransactionsLimit = 250,
    ) {}

    /**
     * @return array{
     *     summary: array<string, int>,
     *     unmatchedOrders: list<array<string, mixed>>,
     *     unmatchedTransactions: list<array<string, mixed>>,
     *     unbalancedOrders: list<array<string, mixed>>,
     *     paymentReviewOrders: list<array<string, mixed>>,
     *     suggestedTransfers: list<array<string, mixed>>,
     *     suggestedIncome: list<array<string, mixed>>,
     *     openReimbursementGroups: list<array<string, mixed>>,
     *     closedReimbursementGroups: list<array<string, mixed>>,
     *     reimbursementEligibleTransactions: list<array<string, mixed>>,
     *     matchedPairs: list<array<string, mixed>>
     * }
     */
    public function forUser(int $userId): array
    {
        $unbalancedOrders = $this->unbalancedOrders($userId);
        $paymentReviewOrders = $this->paymentReviewOrders($userId);
        $suggestedTransfers = $this->suggestedTransfers($userId);
        $suggestedIncome = $this->suggestedIncome($userId);
        $openReimbursementGroups = $this->reimbursementGroupsPayload($userId, ReimbursementGroup::STATUS_OPEN);
        $closedReimbursementGroups = $this->reimbursementGroupsPayload($userId, ReimbursementGroup::STATUS_CLOSED);

        $suggestedTransfersCount = TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->where('status', TransactionTransferLink::STATUS_SUGGESTED)
            ->count();

        $suggestedIncomeCount = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->count();

        $openReimbursementGroupsCount = count($openReimbursementGroups);

        $orderReviewCount = collect($unbalancedOrders)
            ->pluck('id')
            ->merge(collect($paymentReviewOrders)->pluck('id'))
            ->unique()
            ->count();

        return [
            'summary' => $this->summary(
                $userId,
                count($unbalancedOrders),
                count($paymentReviewOrders),
                $suggestedTransfersCount,
                $suggestedIncomeCount,
                $openReimbursementGroupsCount,
                $orderReviewCount + $suggestedTransfersCount + $suggestedIncomeCount + $openReimbursementGroupsCount,
            ),
            'unmatchedOrders' => $this->unmatchedOrders($userId),
            'unmatchedTransactions' => $this->unmatchedTransactions($userId),
            'unbalancedOrders' => $unbalancedOrders,
            'paymentReviewOrders' => $paymentReviewOrders,
            'suggestedTransfers' => $suggestedTransfers,
            'suggestedIncome' => $suggestedIncome,
            'openReimbursementGroups' => $openReimbursementGroups,
            'closedReimbursementGroups' => $closedReimbursementGroups,
            'reimbursementEligibleTransactions' => $this->reimbursementEligibleTransactions($userId),
            'matchedPairs' => $this->matchedPairs($userId),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function summary(
        int $userId,
        int $unbalancedOrdersCount,
        int $paymentReviewOrdersCount,
        int $suggestedTransfersCount,
        int $suggestedIncomeCount,
        int $openReimbursementGroupsCount,
        int $needsReviewCount,
    ): array {
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
            'partial_transactions' => BankTransaction::query()
                ->where('user_id', $userId)
                ->where('status', 'partial')
                ->count(),
            'matched_pairs' => $this->matchedPairCount($userId),
            'unbalanced_orders' => $unbalancedOrdersCount,
            'payment_review_orders' => $paymentReviewOrdersCount,
            'suggested_transfers' => $suggestedTransfersCount,
            'suggested_income' => $suggestedIncomeCount,
            'open_reimbursement_groups' => $openReimbursementGroupsCount,
            'needs_review' => $needsReviewCount,
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

    protected function matchedPairCount(int $userId): int
    {
        return (int) DB::query()
            ->fromSub($this->matchedPairsQuery($userId), 'pairs')
            ->count();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unmatchedOrders(int $userId): array
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'reconciled')
            ->with('merchant:id,name,normalized_name')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'ordered_at' => $order->ordered_at?->toDateString(),
                'total' => (float) $order->total,
                'payment_last_four' => $order->payment_last_four,
                'status' => $order->status,
                'merchant' => $order->merchant?->name,
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
                            'requires_bank_transaction' => in_array($payment['kind'], ['card', 'unknown'], true),
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
    protected function suggestedIncome(int $userId): array
    {
        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->with('account:id,name,last_four,account_type')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(fn (BankTransaction $transaction): array => [
                ...$this->transactionPayload($transaction, includeAccount: true),
                'classification' => $transaction->classification,
                'classification_source' => $transaction->classification_source,
                'classification_confidence' => $transaction->classification_confidence !== null
                    ? (float) $transaction->classification_confidence
                    : null,
            ])
            ->all();
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
            'can_categorize' => (float) $transaction->amount < 0
                && $transaction->status === 'unmatched'
                && $transaction->classification === null
                && ! (bool) ($transaction->merchant?->supports_order_import),
            'can_mark_income' => (float) $transaction->amount > 0
                && $transaction->status === 'unmatched'
                && $transaction->classification === null,
        ];

        if ($includeAccount) {
            $payload['account_id'] = $transaction->account_id;
            $payload['account'] = $transaction->account?->name;
            $payload['account_last_four'] = $transaction->account?->last_four;
            $payload['account_default_classification'] = $transaction->account?->default_classification
                ?? BankTransaction::CLASSIFICATION_EXPENSE;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function matchedPairs(int $userId): array
    {
        $pairRows = $this->matchedPairsQuery($userId)
            ->orderByDesc('matched_at')
            ->orderByDesc('bank_transaction_id')
            ->limit($this->listLimit)
            ->get();

        if ($pairRows->isEmpty()) {
            return [];
        }

        $transactions = BankTransaction::query()
            ->whereIn('id', $pairRows->pluck('bank_transaction_id'))
            ->with('merchant:id,name,normalized_name')
            ->get()
            ->keyBy('id');

        $orders = Order::query()
            ->whereIn('id', $pairRows->pluck('order_id'))
            ->with('merchant:id,name,normalized_name')
            ->get()
            ->keyBy('id');

        return $pairRows
            ->map(function (object $row) use ($transactions, $orders): ?array {
                $transaction = $transactions->get($row->bank_transaction_id);
                $order = $orders->get($row->order_id);

                if (! $transaction || ! $order) {
                    return null;
                }

                return [
                    'allocated_amount' => (float) $row->allocated_amount,
                    'transaction' => [
                        'id' => $transaction->id,
                        'posted_at' => $transaction->posted_at?->toDateString(),
                        'transaction_date' => $transaction->transaction_date?->toDateString(),
                        'description' => $transaction->description,
                        'amount' => (float) $transaction->amount,
                        'card_last_four' => $transaction->card_last_four,
                        'status' => $transaction->status,
                        'merchant' => $transaction->merchant?->name,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'ordered_at' => $order->ordered_at?->toDateString(),
                        'total' => (float) $order->total,
                        'payment_last_four' => $order->payment_last_four,
                        'status' => $order->status,
                        'merchant' => $order->merchant?->name,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function matchedPairsQuery(int $userId)
    {
        return TransactionAllocation::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'transaction_allocations.bank_transaction_id')
            ->join('order_components', 'order_components.id', '=', 'transaction_allocations.order_component_id')
            ->join('orders', 'orders.id', '=', 'order_components.order_id')
            ->where('bank_transactions.user_id', $userId)
            ->groupBy(
                'transaction_allocations.bank_transaction_id',
                'order_components.order_id',
            )
            ->select([
                'transaction_allocations.bank_transaction_id',
                'order_components.order_id',
                DB::raw('SUM(transaction_allocations.allocated_amount) as allocated_amount'),
                DB::raw('MAX(transaction_allocations.created_at) as matched_at'),
            ]);
    }
}
