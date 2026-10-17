<?php

namespace App\Services\Plaid;

use App\Jobs\ProcessImportBatch;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\PlaidAccount;
use App\Models\PlaidItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlaidTransactionSync
{
    public function __construct(
        protected PlaidClient $client,
        protected PlaidLinkService $links,
    ) {}

    public function sync(PlaidItem $item): int
    {
        $startCursor = $item->cursor;
        $attempt = 0;

        while (true) {
            try {
                return $this->pull($item, $startCursor);
            } catch (PlaidRequestException $exception) {
                if ($exception->errorCode === 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION' && $attempt < 3) {
                    $attempt++;
                    $item->refresh();

                    continue;
                }

                throw $exception;
            }
        }
    }

    protected function pull(PlaidItem $item, ?string $startCursor): int
    {
        $accounts = $this->client->accountsGet($item->access_token);
        $cursor = $startCursor;
        $added = [];
        $modified = [];
        $removed = [];
        $nextCursor = $startCursor;
        $status = $item->transactions_update_status;

        do {
            $page = $this->client->transactionsSync($item->access_token, $cursor);
            $added = array_merge($added, $this->rows($page['added'] ?? []));
            $modified = array_merge($modified, $this->rows($page['modified'] ?? []));
            $removed = array_merge($removed, $this->rows($page['removed'] ?? []));
            $nextCursor = is_string($page['next_cursor'] ?? null) ? $page['next_cursor'] : $nextCursor;
            $status = is_string($page['transactions_update_status'] ?? null)
                ? $page['transactions_update_status']
                : $status;
            $cursor = $nextCursor;
        } while (($page['has_more'] ?? false) === true);

        return DB::transaction(function () use ($item, $accounts, $added, $modified, $removed, $nextCursor, $status): int {
            $this->links->storeAccounts($item, $accounts['accounts'] ?? []);
            $item->unsetRelation('accounts');
            $item->load('accounts');

            $created = $this->apply($item, $added, $modified, $removed);

            $item->update([
                'cursor' => $nextCursor,
                'transactions_update_status' => $status,
                'last_synced_at' => now(),
                'error_code' => null,
            ]);

            return $created;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $added
     * @param  list<array<string, mixed>>  $modified
     * @param  list<array<string, mixed>>  $removed
     */
    protected function apply(PlaidItem $item, array $added, array $modified, array $removed): int
    {
        $batch = null;
        $created = 0;

        foreach ([...$added, ...$modified] as $transaction) {
            $result = $this->upsert($item, $transaction, $batch);

            if ($result === 'created') {
                $created++;
            }
        }

        foreach ($removed as $transaction) {
            $this->remove($item, $transaction);
        }

        if ($batch instanceof ImportBatch) {
            $batch->update([
                'record_count' => $created,
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        if ($created > 0 && $batch instanceof ImportBatch) {
            DB::afterCommit(function () use ($batch): void {
                ProcessImportBatch::dispatchFollowUp($batch);
            });
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    protected function upsert(PlaidItem $item, array $transaction, ?ImportBatch &$batch): ?string
    {
        $plaidAccount = $this->mappedAccount($item, $transaction);

        if (! $plaidAccount instanceof PlaidAccount) {
            return null;
        }

        $postedAt = $this->date($transaction['date'] ?? null);

        if ($postedAt === null || $postedAt->lt($item->import_from->copy()->startOfDay())) {
            return null;
        }

        $externalId = $transaction['transaction_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        $attributes = $this->attributes($transaction, $postedAt);
        $pendingId = $transaction['pending_transaction_id'] ?? null;
        $existing = BankTransaction::query()
            ->where('account_id', $plaidAccount->account_id)
            ->where('external_id', $externalId)
            ->first();

        if (! $existing instanceof BankTransaction && is_string($pendingId) && $pendingId !== '') {
            $pending = BankTransaction::query()
                ->where('account_id', $plaidAccount->account_id)
                ->where('external_id', $pendingId)
                ->first();

            if ($pending instanceof BankTransaction) {
                $pending->update([
                    'external_id' => $externalId,
                    ...$attributes,
                ]);

                return 'updated';
            }
        }

        if ($existing instanceof BankTransaction) {
            $existing->update($attributes);

            return 'updated';
        }

        $batch ??= $this->batch($item);

        BankTransaction::query()->create([
            ...$attributes,
            'user_id' => $item->user_id,
            'import_batch_id' => $batch->id,
            'account_id' => $plaidAccount->account_id,
            'external_id' => $externalId,
            'status' => 'unmatched',
        ]);

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    protected function remove(PlaidItem $item, array $transaction): void
    {
        $externalId = $transaction['transaction_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return;
        }

        $accountIds = $item->accounts
            ->pluck('account_id')
            ->filter()
            ->all();

        if ($accountIds === []) {
            return;
        }

        $row = BankTransaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('external_id', $externalId)
            ->first();

        if (! $row instanceof BankTransaction) {
            return;
        }

        if ($row->status !== 'unmatched' || $row->allocations()->exists()) {
            return;
        }

        $row->delete();
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    protected function mappedAccount(PlaidItem $item, array $transaction): ?PlaidAccount
    {
        $accountId = $transaction['account_id'] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        $plaidAccount = $item->accounts->firstWhere('plaid_account_id', $accountId);

        if (! $plaidAccount instanceof PlaidAccount || $plaidAccount->account_id === null) {
            return null;
        }

        return $plaidAccount;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    protected function attributes(array $transaction, Carbon $postedAt): array
    {
        $description = $transaction['original_description']
            ?? $transaction['name']
            ?? $transaction['merchant_name']
            ?? 'Transaction';

        if (! is_string($description) || $description === '') {
            $description = 'Transaction';
        }

        $description = Str::limit($description, 255, '');
        $amount = number_format(((float) ($transaction['amount'] ?? 0)) * -1, 2, '.', '');
        $currency = $transaction['iso_currency_code'] ?? null;

        if (! is_string($currency) || strlen($currency) !== 3) {
            $currency = 'USD';
        }

        $authorized = $this->date($transaction['authorized_date'] ?? null);

        return [
            'posted_at' => $postedAt->toDateString(),
            'transaction_date' => ($authorized ?? $postedAt)->toDateString(),
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => [
                'plaid' => [
                    'transaction_id' => $transaction['transaction_id'] ?? null,
                    'pending' => (bool) ($transaction['pending'] ?? false),
                    'pending_transaction_id' => $transaction['pending_transaction_id'] ?? null,
                    'personal_finance_category' => $transaction['personal_finance_category'] ?? null,
                ],
            ],
        ];
    }

    protected function batch(PlaidItem $item): ImportBatch
    {
        return ImportBatch::query()->create([
            'user_id' => $item->user_id,
            'source' => 'plaid',
            'type' => 'transactions',
            'original_filename' => 'Plaid sync',
            'storage_path' => 'plaid://'.$item->id.'/'.Str::uuid(),
            'record_count' => 0,
            'status' => 'processing',
            'started_at' => now(),
            'metadata' => [
                'plaid_item_id' => $item->id,
            ],
        ]);
    }

    protected function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, is_array(...)));
    }
}
