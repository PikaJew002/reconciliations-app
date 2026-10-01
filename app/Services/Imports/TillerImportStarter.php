<?php

namespace App\Services\Imports;

use App\Jobs\ImportTillerTransactions;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TillerImportStarter
{
    public function __construct(private TillerAccountLinker $accounts) {}

    /**
     * @param  list<array<string, mixed>>  $transactions
     */
    public function start(User $user, array $transactions): void
    {
        $groups = [];

        foreach ($transactions as $transaction) {
            $account = $this->accounts->resolve(
                $user,
                (string) $transaction['account_id'],
                (string) $transaction['account_number'],
            );

            if ($account === null) {
                continue;
            }

            $groups[$account->id]['tiller_account_id'] = (string) $transaction['account_id'];
            $groups[$account->id]['rows'][] = $transaction;
        }

        if ($groups === []) {
            return;
        }

        $syncId = (string) Str::uuid();
        $filename = 'tiller-'.now()->format('Y-m-d-His').'.json';

        foreach ($groups as $accountId => $group) {
            $storagePath = 'imports/'.Str::uuid().'.json';

            Storage::disk('local')->put(
                $storagePath,
                json_encode($group['rows'], JSON_THROW_ON_ERROR),
            );

            ImportBatch::create([
                'user_id' => $user->id,
                'source' => 'tiller',
                'type' => 'transactions',
                'original_filename' => $filename,
                'storage_path' => $storagePath,
                'status' => 'pending',
                'metadata' => [
                    'account_id' => (string) $accountId,
                    'tiller_account_id' => $group['tiller_account_id'],
                    'tiller_sync_id' => $syncId,
                ],
            ]);
        }

        ImportTillerTransactions::dispatch($user->id, $syncId);
    }
}
