<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\ImportBatch;
use App\Services\Imports\Contracts\Importer;
use App\Services\Institutions\InstitutionRegistry;
use InvalidArgumentException;

class ImporterResolver
{
    public function __construct(
        protected InstitutionRegistry $institutions,
    ) {}

    public function resolve(ImportBatch $batch): Importer
    {
        return match ([$batch->source, $batch->type]) {
            ['bank', 'transactions'] => $this->resolveBankTransactionImporter($batch),
            ['walmart', 'orders'] => app(WalmartOrderImporter::class),
            ['amazon', 'orders'] => $this->resolveAmazonOrderImporter($batch),
            ['venmo', 'activity'] => app(VenmoActivityImporter::class),
            default => throw new InvalidArgumentException(
                "No importer registered for source [{$batch->source}] and type [{$batch->type}].",
            ),
        };
    }

    protected function resolveAmazonOrderImporter(ImportBatch $batch): Importer
    {
        $format = $batch->metadata['format'] ?? null;

        if ($format === 'scrape_json' || str_ends_with((string) $batch->storage_path, '.json')) {
            return app(AmazonScrapeOrderImporter::class);
        }

        return app(AmazonOrderImporter::class);
    }

    protected function resolveBankTransactionImporter(ImportBatch $batch): Importer
    {
        $accountId = $batch->metadata['account_id'] ?? null;

        if (! $accountId) {
            throw new InvalidArgumentException('Bank transaction imports require metadata.account_id.');
        }

        $account = Account::query()->find($accountId);

        if (! $account) {
            throw new InvalidArgumentException("Account [{$accountId}] not found.");
        }

        if ($account->user_id !== $batch->user_id) {
            throw new InvalidArgumentException(
                "Account [{$accountId}] does not belong to the import batch owner.",
            );
        }

        $profile = $this->institutions->find($account->institution_name);

        if ($profile === null) {
            throw new InvalidArgumentException(
                "No bank importer registered for institution [{$account->institution_name}].",
            );
        }

        return app($profile->importerClass);
    }
}
