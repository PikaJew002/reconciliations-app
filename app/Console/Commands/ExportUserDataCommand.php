<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Users\ExportUserDataService;
use Illuminate\Console\Command;

class ExportUserDataCommand extends Command
{
    protected $signature = 'user:export-data
                            {user : User id or email}';

    protected $description = 'Export one user\'s database rows to a private SQL file with a temporary download URL';

    public function handle(ExportUserDataService $export): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('No user matched that id or email.');

            return self::FAILURE;
        }

        $this->info("Exporting data for {$user->email} (id {$user->id})...");

        $result = $export->export($user);

        $this->newLine();
        $this->info('Export ready.');
        $this->line('Download URL (expires '.$result['expires_at'].'):');
        $this->line($result['download_url']);
        $this->newLine();
        $this->line('Local import:');
        $this->line('  herd php artisan migrate:fresh');
        $this->line('  mysql -u root laravel < '.$result['download_filename']);
        $this->newLine();
        $this->line('Row counts:');

        foreach ($result['row_counts'] as $table => $count) {
            if ($count > 0) {
                $this->line("  {$table}: {$count}");
            }
        }

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
