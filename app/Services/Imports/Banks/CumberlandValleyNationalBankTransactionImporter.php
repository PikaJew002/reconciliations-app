<?php

namespace App\Services\Imports\Banks;

use App\Services\Imports\BankTransactionImporter;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CumberlandValleyNationalBankTransactionImporter extends BankTransactionImporter
{
    public const INSTITUTION_NAME = 'Cumberland Valley National Bank';

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row): ?array
    {
        $processedDate = trim((string) ($row['Processed Date'] ?? ''));
        $description = trim((string) ($row['Description'] ?? ''));
        $creditOrDebit = trim((string) ($row['Credit or Debit'] ?? ''));
        $amount = trim((string) ($row['Amount'] ?? ''));

        if ($processedDate === '' || $description === '' || $amount === '' || $creditOrDebit === '') {
            return null;
        }

        $postedAt = Carbon::createFromFormat('n/j/y', $processedDate);

        if ($postedAt === false) {
            return null;
        }

        $signedAmount = match (Str::lower($creditOrDebit)) {
            'credit' => abs((float) $amount),
            'debit' => -abs((float) $amount),
            default => null,
        };

        if ($signedAmount === null) {
            return null;
        }

        $postedAtDate = $postedAt->toDateString();

        return [
            'external_id' => $this->fingerprintExternalId($postedAtDate, $signedAmount, $description),
            'posted_at' => $postedAtDate,
            'transaction_date' => $this->extractTransactionDate($description),
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'amount' => $signedAmount,
            'currency' => 'USD',
        ];
    }

    protected function extractTransactionDate(string $description): ?string
    {
        if (! preg_match('/\b(\d{2}\/\d{2}\/\d{2})\b/', $description, $matches)) {
            return null;
        }

        $date = Carbon::createFromFormat('m/d/y', $matches[1]);

        return $date === false ? null : $date->toDateString();
    }
}
