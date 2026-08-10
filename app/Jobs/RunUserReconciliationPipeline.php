<?php

namespace App\Jobs;

use App\Models\ReconciliationRun;
use App\Services\Reconciliation\CreditCardPaymentPairingService;
use App\Services\Reconciliation\IncomeClassificationService;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use App\Services\Reconciliation\ProductMatchingService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\TransactionCategorizationService;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunUserReconciliationPipeline implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reconciliationRunId) {}

    public function handle(
        CreditCardPaymentPairingService $creditCardPaymentPairing,
        TransferPairingService $transferPairing,
        IncomeClassificationService $incomeClassification,
        TransactionCategorizationService $transactionCategorization,
        ProductMatchingService $productMatching,
        OrderComponentGenerator $components,
        MerchantMatcher $matcher,
        OrderPaymentResolutionService $paymentResolution,
        ReconciliationService $reconciliation,
    ): void {
        $run = ReconciliationRun::query()->find($this->reconciliationRunId);

        if (! $run || ! $run->isInProgress()) {
            return;
        }

        $run->markProcessing();

        try {
            $creditCardPayments = $creditCardPaymentPairing->pairForUser($run->user_id);
            $transfers = $transferPairing->pairForUser($run->user_id);
            $income = $incomeClassification->classifyForUser($run->user_id);
            $categorized = $transactionCategorization->categorizeForUser($run->user_id);
            $productsMatched = $productMatching->matchForUser($run->user_id);
            $ordersWithComponents = $components->generateForUser($run->user_id);
            $merchantsMatched = $matcher->matchForUser($run->user_id);
            $nonBankResolved = $paymentResolution->autoResolveNonBankOnlyOrders($run->user_id);
            $transactionsMatched = $reconciliation->reconcileForUser($run->user_id);

            $run->markCompleted([
                'credit_card_payments_confirmed' => $creditCardPayments['confirmed'],
                'credit_card_payments_suggested' => $creditCardPayments['suggested'],
                'transfers_confirmed' => $transfers['confirmed'],
                'transfers_suggested' => $transfers['suggested'],
                'income_learned' => $income['learned'],
                'income_suggested' => $income['suggested'],
                'transactions_categorized' => $categorized['applied'],
                'transactions_categorization_ambiguous' => $categorized['ambiguous'],
                'products_created' => $productsMatched['created'],
                'products_linked' => $productsMatched['linked'],
                'orders_with_components' => $ordersWithComponents,
                'merchants_matched' => $merchantsMatched,
                'non_bank_resolved' => $nonBankResolved,
                'transactions_matched' => $transactionsMatched,
            ]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
