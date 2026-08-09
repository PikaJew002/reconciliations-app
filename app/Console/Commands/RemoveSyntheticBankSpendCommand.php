<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\RemoveSyntheticBankSpendService;
use Illuminate\Console\Command;

class RemoveSyntheticBankSpendCommand extends Command
{
    protected $signature = 'reconcile:remove-synthetic-bank-spend
                            {--user= : Limit removal to a single user id}';

    protected $description = 'Delete synthetic bank-spend orders/components/allocations and reset those transactions to unmatched';

    public function handle(RemoveSyntheticBankSpendService $service): int
    {
        $userId = $this->option('user') !== null
            ? (int) $this->option('user')
            : null;

        if ($this->option('user') !== null && $userId <= 0) {
            $this->error('The --user option must be a positive integer.');

            return self::FAILURE;
        }

        $result = $service->remove($userId);

        $this->info("Deleted {$result['orders_deleted']} synthetic order(s).");
        $this->info("Reset {$result['transactions_reset']} bank transaction(s) to unmatched.");

        return self::SUCCESS;
    }
}
