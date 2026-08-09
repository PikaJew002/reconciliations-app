<?php

namespace App\Jobs;

use App\Models\ReconciliationRun;
use App\Services\Reconciliation\IncomeClassificationService;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\SyntheticBankSpendReconciler;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunUserReconciliationPipeline implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reconciliationRunId) {}

    public function handle(
        TransferPairingService $transferPairing,
        IncomeClassificationService $incomeClassification,
        OrderComponentGenerator $components,
        MerchantMatcher $matcher,
        OrderPaymentResolutionService $paymentResolution,
        ReconciliationService $reconciliation,
        SyntheticBankSpendReconciler $synthetic,
    ): void {
        $run = ReconciliationRun::query()->find($this->reconciliationRunId);

        if (! $run || ! $run->isInProgress()) {
            return;
        }

        $run->markProcessing();

        try {
            $transfers = $transferPairing->pairForUser($run->user_id);
            $income = $incomeClassification->classifyForUser($run->user_id);
            $ordersWithComponents = $components->generateForUser($run->user_id);
            $merchantsMatched = $matcher->matchForUser($run->user_id);
            $nonBankResolved = $paymentResolution->autoResolveNonBankOnlyOrders($run->user_id);
            $transactionsMatched = $reconciliation->reconcileForUser($run->user_id);
            $syntheticMatched = $synthetic->reconcileForUser($run->user_id);

            $run->markCompleted([
                'transfers_confirmed' => $transfers['confirmed'],
                'transfers_suggested' => $transfers['suggested'],
                'income_learned' => $income['learned'],
                'income_suggested' => $income['suggested'],
                'orders_with_components' => $ordersWithComponents,
                'merchants_matched' => $merchantsMatched,
                'non_bank_resolved' => $nonBankResolved,
                'transactions_matched' => $transactionsMatched,
                'synthetic_matched' => $syntheticMatched,
            ]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
