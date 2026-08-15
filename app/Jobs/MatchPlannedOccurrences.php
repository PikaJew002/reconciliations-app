<?php

namespace App\Jobs;

use App\Services\Plans\PlannedOccurrenceMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchPlannedOccurrences implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(PlannedOccurrenceMatcher $matcher): void
    {
        $matcher->matchForUser($this->userId);
    }
}
