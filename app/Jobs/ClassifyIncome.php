<?php

namespace App\Jobs;

use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ClassifyIncome implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(IncomeClassificationService $incomeClassification): void
    {
        $incomeClassification->classifyForUser($this->userId);
    }
}
