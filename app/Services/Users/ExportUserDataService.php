<?php

namespace App\Services\Users;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportUserDataService
{
    public const EXPIRES_AFTER_HOURS = 24;

    /**
     * @return array{
     *     token: string,
     *     user_id: int,
     *     email: string,
     *     expires_at: string,
     *     download_url: string,
     *     download_filename: string,
     *     row_counts: array<string, int>,
     * }
     */
    public function export(User $user): array
    {
        $this->deleteExportForUser($user->id);

        $token = Str::random(64);
        $createdAt = now();
        $expiresAt = $createdAt->copy()->addHours(self::EXPIRES_AFTER_HOURS);
        $downloadFilename = sprintf(
            'spendable-user-%d-%s.sql',
            $user->id,
            $createdAt->format('Y-m-d'),
        );

        $directory = "user-exports/{$token}";
        Storage::disk('local')->makeDirectory($directory);

        $sqlPath = "{$directory}/export.sql";
        $rowCounts = $this->writeSqlExport(
            Storage::disk('local')->path($sqlPath),
            $user,
            $downloadFilename,
            $createdAt,
        );

        $manifest = [
            'token' => $token,
            'user_id' => $user->id,
            'email' => $user->email,
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'download_filename' => $downloadFilename,
            'row_counts' => $rowCounts,
        ];

        Storage::disk('local')->put(
            "{$directory}/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return [
            'token' => $token,
            'user_id' => $user->id,
            'email' => $user->email,
            'expires_at' => $expiresAt->toIso8601String(),
            'download_url' => route('internal.user-export.download', ['token' => $token]),
            'download_filename' => $downloadFilename,
            'row_counts' => $rowCounts,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function manifest(string $token): ?array
    {
        if (! preg_match('/^[a-zA-Z0-9]{64}$/', $token)) {
            return null;
        }

        $path = "user-exports/{$token}/manifest.json";

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $manifest = json_decode(Storage::disk('local')->get($path), true);

        return is_array($manifest) ? $manifest : null;
    }

    public function exportSqlPath(string $token): ?string
    {
        $path = "user-exports/{$token}/export.sql";

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }

    public function isExpired(array $manifest): bool
    {
        $expiresAt = Carbon::parse($manifest['expires_at'] ?? '');

        return $expiresAt->isPast();
    }

    public function deleteExportForUser(int $userId): bool
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('user-exports')) {
            return false;
        }

        $deleted = false;

        foreach ($disk->directories('user-exports') as $directory) {
            $manifestPath = "{$directory}/manifest.json";

            if (! $disk->exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode($disk->get($manifestPath), true);

            if (($manifest['user_id'] ?? null) === $userId) {
                $disk->deleteDirectory($directory);
                $deleted = true;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, int>
     */
    protected function writeSqlExport(
        string $absolutePath,
        User $user,
        string $downloadFilename,
        CarbonInterface $createdAt,
    ): array {
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Could not create export file.');
        }

        $rowCounts = [];

        try {
            $this->writeLine($handle, '-- Spendable user data export');
            $this->writeLine($handle, '-- User: '.$user->id.' ('.$user->email.')');
            $this->writeLine($handle, '-- Generated: '.$createdAt->toIso8601String());
            $this->writeLine($handle, '--');
            $this->writeLine($handle, '-- Local import:');
            $this->writeLine($handle, '--   herd php artisan migrate:fresh');
            $this->writeLine($handle, '--   mysql -u root laravel < '.$downloadFilename);
            $this->writeLine($handle, '--');
            $this->writeLine($handle, '-- Or open this file in TablePlus / Sequel Ace and run it against a fresh schema.');
            $this->writeLine($handle, '');
            $this->writeLine($handle, 'SET NAMES utf8mb4;');
            $this->writeLine($handle, 'SET FOREIGN_KEY_CHECKS=0;');
            $this->writeLine($handle, '');

            foreach ($this->tableQueries($user) as $table => $queryFactory) {
                $rowCounts[$table] = $this->writeTableInserts($handle, $table, $queryFactory());
            }

            $this->writeLine($handle, 'SET FOREIGN_KEY_CHECKS=1;');
        } finally {
            fclose($handle);
        }

        return $rowCounts;
    }

    /**
     * @return array<string, callable(): Builder>
     */
    protected function tableQueries(User $user): array
    {
        $userId = $user->id;

        $orderIds = fn () => DB::table('orders')->where('user_id', $userId)->select('id');
        $transactionIds = fn () => DB::table('bank_transactions')->where('user_id', $userId)->select('id');
        $groupIds = fn () => DB::table('reimbursement_groups')->where('user_id', $userId)->select('id');
        $templateIds = fn () => DB::table('planned_templates')->where('user_id', $userId)->select('id');

        return [
            'users' => fn () => DB::table('users')->where('id', $userId),
            'accounts' => fn () => DB::table('accounts')->where('user_id', $userId),
            'import_batches' => fn () => DB::table('import_batches')->where('user_id', $userId),
            'merchants' => fn () => DB::table('merchants')->where('user_id', $userId),
            'categories' => fn () => DB::table('categories')->where('user_id', $userId),
            'products' => fn () => DB::table('products')->where('user_id', $userId),
            'budget_years' => fn () => DB::table('budget_years')->where('user_id', $userId),
            'budget_category_limits' => fn () => DB::table('budget_category_limits')->where('user_id', $userId),
            'planned_templates' => fn () => DB::table('planned_templates')->where('user_id', $userId),
            'planned_template_assignments' => fn () => DB::table('planned_template_assignments')
                ->where(function (Builder $query) use ($templateIds): void {
                    $query->whereIn('paycheck_template_id', $templateIds())
                        ->orWhereIn('bill_template_id', $templateIds());
                }),
            'planned_occurrences' => fn () => DB::table('planned_occurrences')->where('user_id', $userId),
            'orders' => fn () => DB::table('orders')->where('user_id', $userId),
            'order_items' => fn () => DB::table('order_items')->whereIn('order_id', $orderIds()),
            'order_components' => fn () => DB::table('order_components')->whereIn('order_id', $orderIds()),
            'bank_transactions' => fn () => DB::table('bank_transactions')->where('user_id', $userId),
            'transaction_allocations' => fn () => DB::table('transaction_allocations')
                ->whereIn('bank_transaction_id', $transactionIds()),
            'transaction_transfer_links' => fn () => DB::table('transaction_transfer_links')->where('user_id', $userId),
            'venmo_activities' => fn () => DB::table('venmo_activities')->where('user_id', $userId),
            'pending_spends' => fn () => DB::table('pending_spends')->where('user_id', $userId),
            'transaction_categorization_rules' => fn () => DB::table('transaction_categorization_rules')->where('user_id', $userId),
            'merchant_matching_rules' => fn () => DB::table('merchant_matching_rules')->where('user_id', $userId),
            'categorization_runs' => fn () => DB::table('categorization_runs')->where('user_id', $userId),
            'reconciliation_runs' => fn () => DB::table('reconciliation_runs')->where('user_id', $userId),
            'planned_occurrence_match_runs' => fn () => DB::table('planned_occurrence_match_runs')->where('user_id', $userId),
            'reimbursement_groups' => fn () => DB::table('reimbursement_groups')->where('user_id', $userId),
            'reimbursement_group_transactions' => fn () => DB::table('reimbursement_group_transactions')
                ->whereIn('reimbursement_group_id', $groupIds()),
            'vacation_windows' => fn () => DB::table('vacation_windows')->where('user_id', $userId),
            'personal_access_tokens' => fn () => DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $userId),
        ];
    }

    protected function writeTableInserts($handle, string $table, Builder $query): int
    {
        $this->writeLine($handle, '-- Table: '.$table);

        $count = 0;
        $columns = null;

        $query->orderBy('id')->chunk(200, function ($rows) use ($handle, $table, &$count, &$columns): void {
            if ($rows->isEmpty()) {
                return;
            }

            if ($columns === null) {
                $columns = array_keys((array) $rows->first());
            }

            $columnList = '`'.implode('`, `', $columns).'`';
            $values = $rows
                ->map(function ($row) use ($columns): string {
                    $quoted = collect($columns)
                        ->map(fn (string $column) => $this->quoteValue(((array) $row)[$column] ?? null))
                        ->implode(', ');

                    return '('.$quoted.')';
                })
                ->implode(",\n");

            $this->writeLine($handle, 'INSERT INTO `'.$table.'` ('.$columnList.') VALUES');
            $this->writeLine($handle, $values.';');
            $this->writeLine($handle, '');

            $count += $rows->count();
        });

        if ($count === 0) {
            $this->writeLine($handle, '-- (no rows)');
            $this->writeLine($handle, '');
        }

        return $count;
    }

    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    protected function writeLine($handle, string $line): void
    {
        fwrite($handle, $line."\n");
    }
}
