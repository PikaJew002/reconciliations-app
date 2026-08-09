<?php

namespace App\Jobs;

use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CategorizeTransactions implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(TransactionCategorizationService $categorization): void
    {
        $categorization->categorizeForUser($this->userId);
    }
}
