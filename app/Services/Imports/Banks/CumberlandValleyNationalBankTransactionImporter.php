<?php

namespace App\Services\Imports\Banks;

use App\Services\Imports\BankTransactionImporter;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

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

        $postedAtDate = $this->parseDate($processedDate);

        if ($postedAtDate === null) {
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

        return $this->parseDate($matches[1]);
    }

    /**
     * CVNB exports use either M/D/YY or YYYY-MM-DD depending on download source.
     */
    protected function parseDate(string $value): ?string
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

        return null;
    }
}
