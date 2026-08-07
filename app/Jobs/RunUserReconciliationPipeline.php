<?php

namespace App\Jobs;

use App\Models\ReconciliationRun;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\SyntheticBankSpendReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunUserReconciliationPipeline implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reconciliationRunId) {}

    public function handle(
        OrderComponentGenerator $components,
        MerchantMatcher $matcher,
        ReconciliationService $reconciliation,
        SyntheticBankSpendReconciler $synthetic,
    ): void {
        $run = ReconciliationRun::query()->find($this->reconciliationRunId);

        if (! $run || ! $run->isInProgress()) {
            return;
        }

        $run->markProcessing();

        try {
            $ordersWithComponents = $components->generateForUser($run->user_id);
            $merchantsMatched = $matcher->matchForUser($run->user_id);
            $transactionsMatched = $reconciliation->reconcileForUser($run->user_id);
            $syntheticMatched = $synthetic->reconcileForUser($run->user_id);

            $run->markCompleted([
                'orders_with_components' => $ordersWithComponents,
                'merchants_matched' => $merchantsMatched,
                'transactions_matched' => $transactionsMatched,
                'synthetic_matched' => $syntheticMatched,
            ]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());

            throw $exception;
        }
    }
}
