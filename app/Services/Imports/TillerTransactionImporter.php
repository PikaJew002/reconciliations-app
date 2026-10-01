<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Contracts\Importer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TillerTransactionImporter implements Importer
{
    public function import(ImportBatch $batch): int
    {
        $accountId = $batch->metadata['account_id'] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            throw new RuntimeException('Tiller transaction imports require metadata.account_id.');
        }

        $account = Account::query()->find($accountId);

        if (! $account instanceof Account || $account->user_id !== $batch->user_id) {
            throw new RuntimeException("Account [{$accountId}] not found.");
        }

        $created = 0;
        $acknowledged = [];

        foreach ($this->rows($batch) as $row) {
            $attributes = $this->mapRow($account, $row);
            $transactionId = $row['transaction_id'];
            $fingerprint = $this->fingerprint($account, $attributes);

            $existing = BankTransactionIdentity::find($account->id, [
                ...$attributes,
                'external_id' => $transactionId,
            ], [$fingerprint]);

            if ($existing instanceof BankTransaction) {
                $acknowledged[] = $transactionId;

                continue;
            }

            BankTransaction::query()->create([
                ...$attributes,
                'external_id' => $transactionId,
                'user_id' => $batch->user_id,
                'import_batch_id' => $batch->id,
                'account_id' => $account->id,
                'status' => 'unmatched',
                'metadata' => $row,
            ]);

            $acknowledged[] = $transactionId;
            $created++;
        }

        $batch->update([
            'metadata' => [
                ...($batch->metadata ?? []),
                'acknowledged_transaction_ids' => $acknowledged,
            ],
        ]);

        return $created;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(ImportBatch $batch): array
    {
        $contents = Storage::disk('local')->get($batch->storage_path);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Tiller transaction import file is empty.');
        }

        $rows = json_decode($contents, true);

        if (! is_array($rows)) {
            throw new RuntimeException('Tiller transaction import file is not valid JSON.');
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(Account $account, array $row): array
    {
        $description = (string) $row['full_description'];
        $postedAt = $this->parseDate((string) $row['date']);
        $amount = $this->parseAmount($row['amount']);
        $cardLastFour = $this->cardLastFour($account, $description);

        return [
            'posted_at' => $postedAt,
            'transaction_date' => null,
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'card_last_four' => $cardLastFour,
            'amount' => $amount,
            'currency' => 'USD',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fingerprint(Account $account, array $attributes): string
    {
        $description = (string) $attributes['description'];
        $cardLastFour = $attributes['card_last_four'];
        $material = $account->institution_name === CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME
            ? implode('|', [is_string($cardLastFour) ? $cardLastFour : '', $description])
            : $description;

        return BankTransactionIdentity::fingerprint(
            (string) $attributes['posted_at'],
            $attributes['amount'],
            $material,
        );
    }

    private function cardLastFour(Account $account, string $description): ?string
    {
        if (preg_match('/C#(\d{4})\b/', $description, $matches) === 1) {
            return $matches[1];
        }

        if (
            $account->institution_name === CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME
            && is_string($account->last_four)
            && preg_match('/^\d{4}$/', $account->last_four) === 1
        ) {
            return $account->last_four;
        }

        return null;
    }

    private function parseAmount(mixed $value): string
    {
        if (is_string($value)) {
            $value = str_replace([',', '$', ' '], '', $value);
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function parseDate(string $value): string
    {
        foreach (['Y-m-d', 'n/j/y', 'n/j/Y', 'm/d/y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false) {
                return $date->toDateString();
            }
        }

        return Carbon::parse($value)->toDateString();
    }
}
