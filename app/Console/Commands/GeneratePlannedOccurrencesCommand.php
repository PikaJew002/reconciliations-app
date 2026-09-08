<?php

namespace App\Console\Commands;

use App\Models\PlannedTemplate;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Illuminate\Console\Command;

class GeneratePlannedOccurrencesCommand extends Command
{
    protected $signature = 'plans:generate-occurrences
                            {--user= : Limit generation to a single user id}
                            {--backfill : Recreate missing months since each plan was created}';

    protected $description = 'Generate planned paycheck and bill occurrences up to two months ahead';

    public function handle(
        PlannedOccurrenceGenerator $generator,
        PlannedOccurrenceMatcher $matcher,
    ): int {
        $userId = $this->option('user') !== null
            ? (int) $this->option('user')
            : null;

        if ($this->option('user') !== null && $userId <= 0) {
            $this->error('The --user option must be a positive integer.');

            return self::FAILURE;
        }

        if ($this->option('backfill')) {
            $created = $generator->backfillAll($userId);
            $matched = $this->matchUsers($matcher, $userId);

            $this->info("Backfilled {$created} missing occurrence(s). Matched {$matched}.");

            return self::SUCCESS;
        }

        $synced = $generator->ensureAll($userId);

        $this->info("Synced {$synced} active plan(s).");

        return self::SUCCESS;
    }

    protected function matchUsers(PlannedOccurrenceMatcher $matcher, ?int $userId): int
    {
        $userIds = PlannedTemplate::query()
            ->where('is_active', true)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->distinct()
            ->pluck('user_id');

        $matched = 0;

        foreach ($userIds as $id) {
            $matched += $matcher->matchForUser((int) $id)['matched'];
        }

        return $matched;
    }
}
