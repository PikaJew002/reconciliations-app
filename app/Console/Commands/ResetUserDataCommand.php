<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\ResetUserDataService;
use Illuminate\Console\Command;

class ResetUserDataCommand extends Command
{
    protected $signature = 'user:reset-data
                            {user : User id or email}
                            {--force : Do not ask for confirmation}';

    protected $description = 'Delete a user\'s accounts, imports, files, and related rows. Keeps the login and resets onboarding.';

    public function handle(ResetUserDataService $reset): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('No user matched that id or email.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Delete all data for {$user->email} (id {$user->id})? The login will be kept.",
        )) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $result = $reset->reset($user);

        $this->info("Reset {$user->email}.");
        $this->info("Deleted {$result['accounts_deleted']} account(s), {$result['import_batches_deleted']} import batch(es), and {$result['files_deleted']} stored file(s).");

        return self::SUCCESS;
    }

    protected function resolveUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            return User::query()->find((int) $identifier);
        }

        return User::query()->where('email', $identifier)->first();
    }
}
