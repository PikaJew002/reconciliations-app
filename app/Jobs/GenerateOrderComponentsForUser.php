<?php

namespace App\Jobs;

use App\Services\Reconciliation\OrderComponentGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateOrderComponentsForUser implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(OrderComponentGenerator $generator): void
    {
        $generator->generateForUser($this->userId);
    }
}
