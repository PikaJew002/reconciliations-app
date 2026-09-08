<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\ExportUserDataService;
use Illuminate\Console\Command;

class DeleteUserDataExportCommand extends Command
{
    protected $signature = 'user:delete-export
                            {user : User id or email}';

    protected $description = 'Delete a user\'s pending data export file and download URL';

    public function handle(ExportUserDataService $export): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('No user matched that id or email.');

            return self::FAILURE;
        }

        if (! $export->deleteExportForUser($user->id)) {
            $this->info("No export found for {$user->email} (id {$user->id}).");

            return self::SUCCESS;
        }

        $this->info("Deleted export for {$user->email} (id {$user->id}).");

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
