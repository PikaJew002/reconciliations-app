<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Order;
use App\Models\TransactionAllocation;
use Illuminate\Support\Facades\DB;

class ReconciliationReviewService
{
    public function __construct(
        protected OrderPaymentResolutionService $paymentResolution,
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     summary: array<string, int>,
     *     unmatchedOrders: list<array<string, mixed>>,
     *     unmatchedTransactions: list<array<string, mixed>>,
     *     unbalancedOrders: list<array<string, mixed>>,
     *     paymentReviewOrders: list<array<string, mixed>>,
     *     matchedPairs: list<array<string, mixed>>
     * }
     */
    public function forUser(int $userId): array
    {
        $unbalancedOrders = $this->unbalancedOrders($userId);
        $paymentReviewOrders = $this->paymentReviewOrders($userId);

        $needsReviewIds = collect($unbalancedOrders)
            ->pluck('id')
            ->merge(collect($paymentReviewOrders)->pluck('id'))
            ->unique()
            ->count();

        return [
            'summary' => $this->summary($userId, count($unbalancedOrders), count($paymentReviewOrders), $needsReviewIds),
            'unmatchedOrders' => $this->unmatchedOrders($userId),
            'unmatchedTransactions' => $this->unmatchedTransactions($userId),
            'unbalancedOrders' => $unbalancedOrders,
            'paymentReviewOrders' => $paymentReviewOrders,
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
            'unmatched_transactions' => BankTransaction::query()
                ->where('user_id', $userId)
                ->where('status', 'unmatched')
                ->count(),
            'partial_transactions' => BankTransaction::query()
                ->where('user_id', $userId)
                ->where('status', 'partial')
                ->count(),
            'matched_pairs' => $this->matchedPairCount($userId),
            'unbalanced_orders' => $unbalancedOrdersCount,
            'payment_review_orders' => $paymentReviewOrdersCount,
            'needs_review' => $needsReviewCount,
        ];
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
                        ->map(fn ($component): array => [
                            'id' => $component->id,
                            'type' => $component->type,
                            'description' => $component->description,
                            'amount' => (float) $component->amount,
                            'is_user_modified' => (bool) $component->is_user_modified,
                            'can_delete' => (int) $component->allocations_count === 0,
                        ])
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
        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->with('merchant:id,name,normalized_name')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'posted_at' => $transaction->posted_at?->toDateString(),
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'card_last_four' => $transaction->card_last_four,
                'status' => $transaction->status,
                'merchant' => $transaction->merchant?->name,
            ])
            ->all();
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
