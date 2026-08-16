<?php

namespace App\Services\Users;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetCategoryLimit;
use App\Models\BudgetYear;
use App\Models\CategorizationRun;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceMatchRun;
use App\Models\PlannedTemplate;
use App\Models\Product;
use App\Models\ReconciliationRun;
use App\Models\ReimbursementGroup;
use App\Models\TransactionAllocation;
use App\Models\TransactionCategorizationRule;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Models\VenmoActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetUserDataService
{
    /**
     * @return array{files_deleted: int, accounts_deleted: int, import_batches_deleted: int}
     */
    public function reset(User $user): array
    {
        $batches = ImportBatch::query()
            ->where('user_id', $user->id)
            ->get();

        $filesDeleted = $this->deleteImportFiles($batches);
        $batchCount = $batches->count();
        $accountCount = Account::withTrashed()->where('user_id', $user->id)->count();

        DB::transaction(function () use ($user): void {
            $userId = $user->id;

            TransactionCategorizationRule::query()->where('user_id', $userId)->delete();
            PlannedOccurrence::query()->where('user_id', $userId)->delete();
            PlannedTemplate::query()->where('user_id', $userId)->delete();
            PlannedOccurrenceMatchRun::query()->where('user_id', $userId)->delete();
            BudgetCategoryLimit::query()->where('user_id', $userId)->delete();
            BudgetYear::query()->where('user_id', $userId)->delete();
            ReimbursementGroup::query()->where('user_id', $userId)->delete();
            TransactionTransferLink::query()->where('user_id', $userId)->delete();
            VenmoActivity::query()->where('user_id', $userId)->delete();

            $transactionIds = BankTransaction::query()
                ->where('user_id', $userId)
                ->pluck('id');

            if ($transactionIds->isNotEmpty()) {
                TransactionAllocation::query()
                    ->whereIn('bank_transaction_id', $transactionIds)
                    ->delete();
            }

            BankTransaction::query()->where('user_id', $userId)->delete();
            Order::query()->where('user_id', $userId)->delete();
            Product::query()->where('user_id', $userId)->delete();
            Merchant::query()->where('user_id', $userId)->delete();
            Category::query()->where('user_id', $userId)->delete();
            CategorizationRun::query()->where('user_id', $userId)->delete();
            ReconciliationRun::query()->where('user_id', $userId)->delete();
            ImportBatch::query()->where('user_id', $userId)->delete();
            Account::withTrashed()->where('user_id', $userId)->forceDelete();

            $user->forceFill([
                'onboarding_hidden_at' => null,
                'onboarding_skipped_steps' => null,
                'onboarding_tours' => null,
            ])->save();
        });

        return [
            'files_deleted' => $filesDeleted,
            'accounts_deleted' => $accountCount,
            'import_batches_deleted' => $batchCount,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ImportBatch>  $batches
     */
    protected function deleteImportFiles($batches): int
    {
        $deleted = 0;
        $disk = Storage::disk('local');

        foreach ($batches as $batch) {
            $paths = array_filter([
                $batch->storage_path,
                $batch->metadata['items_path'] ?? null,
            ]);

            foreach ($paths as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                    $deleted++;
                }

                $directory = dirname((string) $path);

                if (preg_match('#^imports/[0-9a-f-]{36}$#i', $directory) && $disk->exists($directory)) {
                    $disk->deleteDirectory($directory);
                }
            }
        }

        return $deleted;
    }
}
