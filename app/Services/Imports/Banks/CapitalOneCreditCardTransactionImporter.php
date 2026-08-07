<?php

namespace App\Services\Imports\Banks;

use App\Services\Imports\BankTransactionImporter;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

class CapitalOneCreditCardTransactionImporter extends BankTransactionImporter
{
    public const INSTITUTION_NAME = 'Capital One';

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row): ?array
    {
        $transactionDate = trim((string) ($row['Transaction Date'] ?? ''));
        $postedDate = trim((string) ($row['Posted Date'] ?? ''));
        $cardNo = trim((string) ($row['Card No.'] ?? ''));
        $description = trim((string) ($row['Description'] ?? ''));
        $debit = trim((string) ($row['Debit'] ?? ''));
        $credit = trim((string) ($row['Credit'] ?? ''));

        if ($postedDate === '' || $description === '') {
            return null;
        }

        $postedAtDate = $this->parseDate($postedDate);

        if ($postedAtDate === null) {
            return null;
        }

        $hasDebit = $debit !== '';
        $hasCredit = $credit !== '';

        if ($hasDebit === $hasCredit) {
            return null;
        }

        $signedAmount = $hasDebit
            ? -abs((float) $debit)
            : abs((float) $credit);

        $transactionAtDate = $transactionDate !== ''
            ? $this->parseDate($transactionDate)
            : null;

        $cardLastFour = preg_match('/^\d{4}$/', $cardNo) === 1 ? $cardNo : null;

        return [
            'external_id' => $this->fingerprintExternalId(
                $postedAtDate,
                $signedAmount,
                implode('|', [$cardLastFour ?? '', $description]),
            ),
            'posted_at' => $postedAtDate,
            'transaction_date' => $transactionAtDate,
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'card_last_four' => $cardLastFour,
            'amount' => $signedAmount,
            'currency' => 'USD',
        ];
    }

    /**
     * Capital One exports use YYYY-MM-DD dates.
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
