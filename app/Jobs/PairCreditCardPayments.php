<?php

namespace App\Jobs;

use App\Services\Reconciliation\CreditCardPaymentPairingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PairCreditCardPayments implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(CreditCardPaymentPairingService $pairing): void
    {
        $pairing->pairForUser($this->userId);
    }
}
