<?php

namespace App\Jobs;

use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunReconciliation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(ReconciliationService $reconciliation): void
    {
        $reconciliation->reconcileForUser($this->userId);
    }
}
