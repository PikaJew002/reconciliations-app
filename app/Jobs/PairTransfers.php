<?php

namespace App\Jobs;

use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PairTransfers implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(TransferPairingService $pairing): void
    {
        $pairing->pairForUser($this->userId);
    }
}
