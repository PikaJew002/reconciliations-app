<?php

namespace App\Services\Imports\Banks;

use App\Services\Imports\BankTransactionImporter;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

class CumberlandValleyCreditCardTransactionImporter extends BankTransactionImporter
{
    public const INSTITUTION_NAME = 'Cumberland Valley National Bank Credit Card';

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row): ?array
    {
        $accountNumber = trim((string) ($row['Account Number'] ?? ''));
        $transDate = trim((string) ($row['Trans Date'] ?? ''));
        $postingDate = trim((string) ($row['Posting Date'] ?? ''));
        $type = trim((string) ($row['Type'] ?? ''));
        $merchantName = Str::of((string) ($row['Merchant Name'] ?? ''))->trim()->squish()->toString();
        $amount = trim((string) ($row['Amount'] ?? ''));
        $referenceNumber = trim((string) ($row['Reference Number'] ?? ''));

        if ($postingDate === '' || $merchantName === '' || $amount === '' || $type === '') {
            return null;
        }

        $postedAtDate = $this->parseDate($postingDate);

        if ($postedAtDate === null) {
            return null;
        }

        $parsedAmount = $this->parseAmount($amount);

        if ($parsedAmount === null) {
            return null;
        }

        $signedAmount = match (Str::lower($type)) {
            'credit' => abs($parsedAmount),
            'debit' => -abs($parsedAmount),
            default => null,
        };

        if ($signedAmount === null) {
            return null;
        }

        $transactionAtDate = $transDate !== ''
            ? $this->parseDate($transDate)
            : null;

        $cardLastFour = $this->extractCardLastFour($accountNumber);

        $externalId = $referenceNumber !== ''
            ? $referenceNumber
            : $this->fingerprintExternalId(
                $postedAtDate,
                $signedAmount,
                implode('|', [$cardLastFour ?? '', $merchantName]),
            );

        return [
            'external_id' => $externalId,
            'posted_at' => $postedAtDate,
            'transaction_date' => $transactionAtDate,
            'description' => $merchantName,
            'normalized_description' => Str::of($merchantName)->lower()->squish()->toString(),
            'card_last_four' => $cardLastFour,
            'amount' => $signedAmount,
            'currency' => 'USD',
        ];
    }

    protected function extractCardLastFour(string $accountNumber): ?string
    {
        if (preg_match('/(\d{4})\s*$/', $accountNumber, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function parseAmount(string $value): ?float
    {
        $normalized = str_replace([',', '$', ' '], '', $value);

        // Credits are often exported as accounting negatives, e.g. ($50.00).
        if (preg_match('/^\((.+)\)$/', $normalized, $matches) === 1) {
            $normalized = $matches[1];
        }

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    /**
     * CVNB credit card exports use MM/DD/YYYY dates.
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
