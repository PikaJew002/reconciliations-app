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
            ['amazon', 'orders'] => app(AmazonOrderImporter::class),
            default => throw new InvalidArgumentException(
                "No importer registered for source [{$batch->source}] and type [{$batch->type}].",
            ),
        };
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

        $profile = $this->institutions->find($account->institution_name);

        if ($profile === null) {
            throw new InvalidArgumentException(
                "No bank importer registered for institution [{$account->institution_name}].",
            );
        }

        return app($profile->importerClass);
    }
}
