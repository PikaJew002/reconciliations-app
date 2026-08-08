<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class OrderPaymentResolutionService
{
    public function __construct(
        protected ReconciliationService $reconciliation,
        protected int $dateWindowDays = 7,
    ) {}

    /**
     * @param  list<array{index: int, amount: float|int|string, bank_transaction_id?: int|null}>  $resolutions
     */
    public function resolve(Order $order, array $resolutions): void
    {
        if ($order->status === 'reconciled') {
            throw new InvalidArgumentException('Reconciled orders cannot be edited.');
        }

        $payments = $this->normalizedPayments($order);

        if ($payments === []) {
            throw new InvalidArgumentException('Order does not have payment methods to resolve.');
        }

        $byIndex = [];

        foreach ($resolutions as $resolution) {
            $index = (int) ($resolution['index'] ?? -1);

            if (! array_key_exists($index, $payments)) {
                throw new InvalidArgumentException("Invalid payment index [{$index}].");
            }

            $byIndex[$index] = [
                'amount' => round((float) $resolution['amount'], 2),
                'bank_transaction_id' => isset($resolution['bank_transaction_id'])
                    ? (int) $resolution['bank_transaction_id']
                    : null,
            ];
        }

        if (count($byIndex) !== count($payments)) {
            throw new InvalidArgumentException('Every payment method must be resolved.');
        }

        $resolvedTotal = round(array_sum(array_column($byIndex, 'amount')), 2);

        if (abs($resolvedTotal - (float) $order->total) >= 0.01) {
            throw new InvalidArgumentException('Payment amounts must equal the order total.');
        }

        DB::transaction(function () use ($order, $payments, $byIndex): void {
            $order->refresh();
            $order->load(['components.allocations', 'merchant', 'importBatch']);

            $componentSum = round((float) $order->components->sum('amount'), 2);

            if (abs($componentSum - (float) $order->total) >= 0.01) {
                throw new RuntimeException('Order components must balance before resolving payments.');
            }

            $transactions = collect();
            $updatedPayments = [];
            $primaryCardLastFour = null;

            foreach ($payments as $index => $payment) {
                $resolution = $byIndex[$index];
                $amount = $resolution['amount'];

                if ($amount < 0.01) {
                    throw new InvalidArgumentException('Each payment amount must be greater than zero.');
                }

                $kind = $payment['kind'];
                $requiresBankTx = in_array($kind, ['card', 'unknown'], true);

                if ($requiresBankTx) {
                    $transactionId = $resolution['bank_transaction_id'];

                    if (! $transactionId) {
                        throw new InvalidArgumentException("Payment [{$payment['ending']}] requires a bank transaction.");
                    }

                    $transaction = BankTransaction::query()
                        ->where('user_id', $order->user_id)
                        ->whereKey($transactionId)
                        ->first();

                    if (! $transaction) {
                        throw new InvalidArgumentException('Bank transaction not found.');
                    }

                    if ($transaction->status !== 'unmatched') {
                        throw new InvalidArgumentException('Bank transaction is not unmatched.');
                    }

                    if ($transaction->merchant_id !== $order->merchant_id) {
                        throw new InvalidArgumentException('Bank transaction merchant does not match the order.');
                    }

                    if (abs(abs((float) $transaction->amount) - $amount) >= 0.01) {
                        throw new InvalidArgumentException('Bank transaction amount must match the payment amount.');
                    }

                    if (
                        $payment['last_four'] !== null
                        && $transaction->card_last_four !== null
                        && $payment['last_four'] !== $transaction->card_last_four
                    ) {
                        throw new InvalidArgumentException('Bank transaction card does not match the payment method.');
                    }

                    $primaryCardLastFour ??= $payment['last_four'] ?? $transaction->card_last_four;
                    $transactions->push($transaction);

                    $updatedPayments[] = [
                        ...$payment,
                        'amount' => $amount,
                        'bank_transaction_id' => $transaction->id,
                        'resolved' => true,
                    ];

                    continue;
                }

                $synthetic = $this->createNonBankTenderTransaction($order, $payment, $amount);
                $transactions->push($synthetic);

                $updatedPayments[] = [
                    ...$payment,
                    'amount' => $amount,
                    'bank_transaction_id' => $synthetic->id,
                    'resolved' => true,
                ];
            }

            $metadata = $order->metadata ?? [];
            $metadata['payments'] = $updatedPayments;
            $metadata['payment_resolution'] = [
                'resolved_at' => now()->toIso8601String(),
                'source' => 'manual',
            ];

            $order->update([
                'payment_last_four' => $primaryCardLastFour,
                'metadata' => $metadata,
            ]);

            if (! $this->reconciliation->allocateExactTransactions($transactions, $order->fresh())) {
                throw new RuntimeException('Unable to allocate resolved payments to the order.');
            }
        });
    }

    /**
     * @return list<array{ending: string, last_four: string|null, amount: float|null, kind: string}>
     */
    public function normalizedPayments(Order $order): array
    {
        $raw = $order->metadata['payments'] ?? [];

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $payments = [];

        foreach (array_values($raw) as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $ending = trim((string) ($payment['ending'] ?? ''));

            if ($ending === '') {
                continue;
            }

            $lastFour = $payment['last_four'] ?? null;
            $lastFour = is_string($lastFour) && $lastFour !== '' ? $lastFour : null;
            $kind = $payment['kind'] ?? $this->classifyPaymentKind($ending, $lastFour);

            $payments[] = [
                'ending' => $ending,
                'last_four' => $lastFour,
                'amount' => isset($payment['amount']) && $payment['amount'] !== null
                    ? (float) $payment['amount']
                    : null,
                'kind' => $kind,
            ];
        }

        return $payments;
    }

    public function needsPaymentReview(Order $order): bool
    {
        if ($order->status === 'reconciled') {
            return false;
        }

        $payments = $this->normalizedPayments($order);

        if (count($payments) < 2) {
            return false;
        }

        foreach ($payments as $payment) {
            if ($payment['amount'] === null) {
                return true;
            }
        }

        return $order->payment_last_four === null;
    }

    /**
     * Auto-reconcile orders paid only with known non-bank tenders (gift card / balance).
     *
     * @return int Number of orders resolved.
     */
    public function autoResolveNonBankOnlyOrders(int $userId): int
    {
        $count = 0;

        Order::query()
            ->where('user_id', $userId)
            ->where('status', '!=', 'reconciled')
            ->with(['components', 'merchant', 'importBatch'])
            ->orderBy('id')
            ->each(function (Order $order) use (&$count): void {
                if (! $this->canAutoResolveNonBankOnly($order)) {
                    return;
                }

                $payments = $this->normalizedPayments($order);
                $resolutions = [];

                foreach ($payments as $index => $payment) {
                    $resolutions[] = [
                        'index' => $index,
                        'amount' => $payment['amount'],
                        'bank_transaction_id' => null,
                    ];
                }

                try {
                    $this->resolve($order, $resolutions);
                    $count++;
                } catch (\Throwable) {
                    // Leave for manual review if allocation fails.
                }
            });

        return $count;
    }

    protected function canAutoResolveNonBankOnly(Order $order): bool
    {
        $payments = $this->normalizedPayments($order);

        if ($payments === []) {
            return false;
        }

        $nonBankKinds = ['gift_card', 'walmart_balance'];

        foreach ($payments as $payment) {
            if (! in_array($payment['kind'], $nonBankKinds, true)) {
                return false;
            }

            if ($payment['amount'] === null || (float) $payment['amount'] < 0.01) {
                return false;
            }
        }

        $paymentSum = round(array_sum(array_map(
            fn (array $payment): float => (float) $payment['amount'],
            $payments,
        )), 2);

        if (abs($paymentSum - (float) $order->total) >= 0.01) {
            return false;
        }

        $order->loadMissing('components');

        if ($order->components->isEmpty()) {
            return false;
        }

        $componentSum = round((float) $order->components->sum('amount'), 2);

        return abs($componentSum - (float) $order->total) < 0.01;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidateTransactionsForPayment(Order $order, array $payment): array
    {
        if (! in_array($payment['kind'], ['card', 'unknown'], true)) {
            return [];
        }

        $orderDate = $order->ordered_at ?? $order->delivered_at;

        return BankTransaction::query()
            ->where('user_id', $order->user_id)
            ->where('merchant_id', $order->merchant_id)
            ->where('status', 'unmatched')
            ->where('amount', '<', 0)
            ->when(
                $payment['last_four'] !== null,
                fn ($query) => $query->where('card_last_four', $payment['last_four']),
            )
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->filter(function (BankTransaction $transaction) use ($order, $orderDate): bool {
                if (abs((float) $transaction->amount) - (float) $order->total > 0.01) {
                    return false;
                }

                if ($orderDate === null || $transaction->posted_at === null) {
                    return true;
                }

                return abs($transaction->posted_at->startOfDay()->diffInDays($orderDate->copy()->startOfDay(), false))
                    <= $this->dateWindowDays + 7;
            })
            ->values()
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'posted_at' => $transaction->posted_at?->toDateString(),
                'transaction_date' => $transaction->transaction_date?->toDateString(),
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'card_last_four' => $transaction->card_last_four,
            ])
            ->all();
    }

    /**
     * @param  array{ending: string, last_four: string|null, kind: string}  $payment
     */
    protected function createNonBankTenderTransaction(Order $order, array $payment, float $amount): BankTransaction
    {
        $account = $this->resolveAccountForSynthetic($order);

        return BankTransaction::query()->create([
            'user_id' => $order->user_id,
            'import_batch_id' => $order->import_batch_id,
            'account_id' => $account->id,
            'merchant_id' => $order->merchant_id,
            'external_id' => 'non-bank-'.$order->id.'-'.Str::slug($payment['kind']).'-'.Str::uuid(),
            'posted_at' => ($order->ordered_at ?? $order->delivered_at ?? now())->toDateString(),
            'transaction_date' => ($order->ordered_at ?? $order->delivered_at ?? now())->toDateString(),
            'description' => $payment['ending'],
            'normalized_description' => Str::of($payment['ending'])->lower()->squish()->toString(),
            'card_last_four' => $payment['last_four'],
            'amount' => -abs($amount),
            'currency' => $order->currency ?? 'USD',
            'status' => 'unmatched',
            'notes' => null,
            'metadata' => [
                'source' => 'non_bank_tender',
                'kind' => $payment['kind'],
                'order_id' => $order->id,
            ],
        ]);
    }

    protected function resolveAccountForSynthetic(Order $order): Account
    {
        $accountId = $order->importBatch?->metadata['account_id'] ?? null;

        if ($accountId) {
            $account = Account::query()->find($accountId);

            if ($account) {
                return $account;
            }
        }

        $fromTransaction = BankTransaction::query()
            ->where('user_id', $order->user_id)
            ->whereNotNull('account_id')
            ->latest('id')
            ->first();

        if ($fromTransaction?->account_id) {
            $account = Account::query()->find($fromTransaction->account_id);

            if ($account) {
                return $account;
            }
        }

        $account = Account::query()->where('is_active', true)->orderBy('id')->first();

        if (! $account) {
            throw new RuntimeException('No account available for non-bank tender transaction.');
        }

        return $account;
    }

    protected function classifyPaymentKind(string $ending, ?string $lastFour): string
    {
        $lower = Str::of($ending)->lower()->squish()->toString();

        if (str_contains($lower, 'walmart balance') || $lower === 'balance') {
            return 'walmart_balance';
        }

        if (str_contains($lower, 'gift')) {
            return 'gift_card';
        }

        if (preg_match('/\b(mastercard|visa|amex|american express|discover)\b/', $lower) === 1) {
            return 'card';
        }

        if ($lastFour !== null && preg_match('/^ending in \d{4}$/', $lower) === 1) {
            return 'gift_card';
        }

        if ($lastFour !== null) {
            return 'card';
        }

        return 'unknown';
    }
}
