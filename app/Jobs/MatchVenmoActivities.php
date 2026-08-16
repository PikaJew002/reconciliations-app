<?php

namespace App\Jobs;

use App\Services\Reconciliation\VenmoActivityMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchVenmoActivities implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(VenmoActivityMatcher $matcher): void
    {
        $matcher->matchForUser($this->userId);
    }
}
