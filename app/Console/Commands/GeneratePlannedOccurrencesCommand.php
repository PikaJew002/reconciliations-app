<?php

namespace App\Console\Commands;

use App\Services\Plans\PlannedOccurrenceGenerator;
use Illuminate\Console\Command;

class GeneratePlannedOccurrencesCommand extends Command
{
    protected $signature = 'plans:generate-occurrences
                            {--user= : Limit generation to a single user id}';

    protected $description = 'Generate planned paycheck and bill occurrences up to two months ahead';

    public function handle(PlannedOccurrenceGenerator $generator): int
    {
        $userId = $this->option('user') !== null
            ? (int) $this->option('user')
            : null;

        if ($this->option('user') !== null && $userId <= 0) {
            $this->error('The --user option must be a positive integer.');

            return self::FAILURE;
        }

        $synced = $generator->ensureAll($userId);

        $this->info("Synced {$synced} active plan(s).");

        return self::SUCCESS;
    }
}
