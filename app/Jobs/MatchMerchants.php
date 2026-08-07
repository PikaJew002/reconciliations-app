<?php

namespace App\Jobs;

use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchMerchants implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(MerchantMatcher $matcher): void
    {
        $matcher->matchForUser($this->userId);
    }
}
