<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Merchant;
use Illuminate\Support\Str;

class MerchantMatcher
{
    /**
     * @var list<array{patterns: list<string>, normalized_name: string}>
     */
    protected array $rules = [
        [
            'patterns' => ['wal-mart', 'walmart', 'wal mart', 'wm supercenter', 'walmart.com'],
            'normalized_name' => 'walmart',
        ],
    ];

    /**
     * @return int Number of transactions matched to a merchant.
     */
    public function matchForUser(int $userId): int
    {
        $count = 0;

        BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNull('merchant_id')
            ->where('status', 'unmatched')
            ->orderBy('id')
            ->each(function (BankTransaction $transaction) use ($userId, &$count): void {
                if ($this->matchTransaction($transaction, $userId)) {
                    $count++;
                }
            });

        return $count;
    }

    public function matchTransaction(BankTransaction $transaction, int $userId): bool
    {
        if ($transaction->merchant_id !== null) {
            return false;
        }

        $description = $transaction->normalized_description ?? Str::of($transaction->description)->lower()->squish()->toString();

        foreach ($this->rules as $rule) {
            if (! $this->descriptionMatches($description, $rule['patterns'])) {
                continue;
            }

            $merchant = Merchant::query()
                ->where('user_id', $userId)
                ->where('normalized_name', $rule['normalized_name'])
                ->first();

            if (! $merchant) {
                continue;
            }

            $transaction->update(['merchant_id' => $merchant->id]);

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    protected function descriptionMatches(string $description, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($description, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
