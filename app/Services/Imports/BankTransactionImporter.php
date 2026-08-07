<?php

namespace App\Services\Imports;

use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Services\Imports\Concerns\ReadsCsv;
use App\Services\Imports\Contracts\Importer;
use RuntimeException;

abstract class BankTransactionImporter implements Importer
{
    use ReadsCsv;

    public function import(ImportBatch $batch): int
    {
        $accountId = $batch->metadata['account_id'] ?? null;

        if (! $accountId) {
            throw new RuntimeException('Bank transaction imports require metadata.account_id.');
        }

        $created = 0;

        foreach ($this->rows($batch->storage_path) as $row) {
            $attributes = $this->mapRow($row);

            if ($attributes === null) {
                continue;
            }

            $externalId = $attributes['external_id'] ?? null;

            if ($externalId === null || $externalId === '') {
                throw new RuntimeException('Bank transaction imports require an external_id for deduplication.');
            }

            $transaction = BankTransaction::query()->firstOrCreate(
                [
                    'account_id' => $accountId,
                    'external_id' => $externalId,
                ],
                [
                    ...$attributes,
                    'user_id' => $batch->user_id,
                    'import_batch_id' => $batch->id,
                    'status' => 'unmatched',
                    'metadata' => $row,
                ],
            );

            if ($transaction->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Stable fingerprint used as external_id when the bank does not supply one.
     */
    protected function fingerprintExternalId(string $postedAt, float|string $amount, string $description): string
    {
        $normalizedAmount = number_format((float) $amount, 2, '.', '');

        return hash('sha256', implode('|', [$postedAt, $normalizedAmount, $description]));
    }

    /**
     * Map a CSV row to BankTransaction attributes.
     *
     * Expected keys: external_id, posted_at, transaction_date,
     * description, normalized_description, amount, currency.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    abstract protected function mapRow(array $row): ?array;
}
