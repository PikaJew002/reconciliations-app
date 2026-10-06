<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\OrderPaymentResolutionService;
use Illuminate\Console\Command;

class CorrectBankLinkedGiftCardsCommand extends Command
{
    protected $signature = 'orders:correct-bank-linked-gift-cards
                            {--user= : Limit the correction to a single user id}';

    protected $description = 'Relabel bank-linked "Ending in" payments that were stored as gift cards';

    public function handle(OrderPaymentResolutionService $payments): int
    {
        $userId = $this->option('user') !== null
            ? (int) $this->option('user')
            : null;

        if ($this->option('user') !== null && $userId <= 0) {
            $this->error('The --user option must be a positive integer.');

            return self::FAILURE;
        }

        $corrected = $payments->correctBankLinkedGiftCardPayments($userId);

        $this->info("Corrected {$corrected} order(s).");

        return self::SUCCESS;
    }
}
