<?php

namespace App\Services\Imports;

use App\Models\BankTransaction;

class BankTransactionIdentity
{
    public static function fingerprint(string $postedAt, float|string $amount, string $description): string
    {
        $normalizedAmount = number_format((float) $amount, 2, '.', '');

        return hash('sha256', implode('|', [$postedAt, $normalizedAmount, $description]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $externalIds
     */
    public static function find(string $accountId, array $attributes, array $externalIds = []): ?BankTransaction
    {
        $ids = [];

        foreach ([...$externalIds, $attributes['external_id'] ?? null] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = $id;
            }
        }

        if ($ids !== []) {
            $match = BankTransaction::query()
                ->where('account_id', $accountId)
                ->whereIn('external_id', array_values($ids))
                ->first();

            if ($match instanceof BankTransaction) {
                return $match;
            }
        }

        $postedAt = $attributes['posted_at'] ?? null;
        $description = $attributes['description'] ?? null;
        $amount = $attributes['amount'] ?? null;

        if (! is_string($postedAt) || $postedAt === '' || ! is_string($description) || ! is_scalar($amount)) {
            return null;
        }

        $query = BankTransaction::query()
            ->where('account_id', $accountId)
            ->whereDate('posted_at', $postedAt)
            ->where('amount', number_format((float) $amount, 2, '.', ''))
            ->where('description', $description);

        $cardLastFour = $attributes['card_last_four'] ?? null;

        if (is_string($cardLastFour) && $cardLastFour !== '') {
            $query->where('card_last_four', $cardLastFour);
        }

        $match = $query->first();

        return $match instanceof BankTransaction ? $match : null;
    }
}
