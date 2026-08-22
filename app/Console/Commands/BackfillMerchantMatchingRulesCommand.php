<?php

namespace App\Console\Commands;

use App\Services\Merchants\MerchantMatchingRuleBackfill;
use Illuminate\Console\Command;

class BackfillMerchantMatchingRulesCommand extends Command
{
    protected $signature = 'merchants:backfill-matching-rules
                            {--user= : Limit backfill to a single user id}';

    protected $description = 'Create merchant matching rules from existing transaction assignments';

    public function handle(MerchantMatchingRuleBackfill $backfill): int
    {
        $userId = $this->option('user') !== null
            ? (int) $this->option('user')
            : null;

        if ($this->option('user') !== null && $userId <= 0) {
            $this->error('The --user option must be a positive integer.');

            return self::FAILURE;
        }

        $result = $backfill->backfill($userId);

        $this->info(sprintf(
            'Backfilled %d user(s): created %d rule(s), %d unexplained, %d collision(s).',
            $result['users'],
            $result['rules_created'],
            $result['unexplained'],
            $result['collisions'],
        ));

        return self::SUCCESS;
    }
}
