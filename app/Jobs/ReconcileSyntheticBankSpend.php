<?php

namespace App\Jobs;

use App\Services\Reconciliation\SyntheticBankSpendReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSyntheticBankSpend implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(SyntheticBankSpendReconciler $reconciler): void
    {
        $reconciler->reconcileForUser($this->userId);
    }
}
