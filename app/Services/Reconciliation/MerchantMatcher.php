<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Merchant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MerchantMatcher
{
    public function __construct(
        protected MerchantNameExtractorResolver $extractorResolver,
        protected float $fuzzyMatchThreshold = 0.85,
    ) {}

    /**
     * @var list<array{patterns: list<string>, normalized_name: string}>
     */
    protected array $rules = [
        [
            'patterns' => ['wal-mart', 'walmart', 'wal mart', 'wm supercenter', 'walmart.com'],
            'normalized_name' => 'walmart',
        ],
        [
            'patterns' => ['amazon', 'amzn', 'amzn mktp', 'amazon.com', 'amazon mktpl'],
            'normalized_name' => 'amazon',
        ],
    ];

    /**
     * @var list<string>
     */
    protected array $amazonPatterns = [
        'amazon',
        'amzn',
        'amzn mktp',
        'amazon.com',
        'amazon mktpl',
    ];

    /**
     * @var list<string>
     */
    protected array $noisePatterns = [
        'transfer',
        'venmo',
        'deposit',
        'atm',
        'withdrawal',
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
            ->with('account')
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

        $description = $transaction->normalized_description
            ?? Str::of($transaction->description)->lower()->squish()->toString();

        if ($this->shouldSkipDescription($description)) {
            return false;
        }

        foreach ($this->rules as $rule) {
            if (! $this->descriptionMatches($description, $rule['patterns'])) {
                continue;
            }

            $merchant = Merchant::query()
                ->where('user_id', $userId)
                ->where('normalized_name', $rule['normalized_name'])
                ->first();

            if (! $merchant) {
                return false;
            }

            $transaction->update(['merchant_id' => $merchant->id]);

            return true;
        }

        if ((float) $transaction->amount >= 0) {
            return false;
        }

        $transaction->loadMissing('account');
        $extractor = $this->extractorResolver->resolve($transaction->account?->institution_name);

        if (! $extractor->canExtract($description)) {
            return false;
        }

        $extracted = $extractor->extract($description);

        if ($extracted === null) {
            return false;
        }

        if ($this->looksLikeAmazon($extracted['normalized_name'])) {
            return false;
        }

        $merchant = $this->findFuzzyMerchant($userId, $extracted['normalized_name'])
            ?? $this->createMerchant($userId, $extracted['display_name'], $extracted['normalized_name']);

        $transaction->update(['merchant_id' => $merchant->id]);

        return true;
    }

    protected function shouldSkipDescription(string $description): bool
    {
        foreach ($this->noisePatterns as $pattern) {
            if (str_contains($description, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeAmazon(string $value): bool
    {
        return $this->descriptionMatches($value, $this->amazonPatterns);
    }

    protected function findFuzzyMerchant(int $userId, string $normalizedName): ?Merchant
    {
        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->get(['id', 'name', 'normalized_name', 'user_id', 'type', 'supports_order_import', 'supports_api', 'website', 'metadata']);

        $exact = $merchants->first(
            fn (Merchant $merchant): bool => $merchant->normalized_name === $normalizedName
        );

        if ($exact) {
            return $exact;
        }

        $bestMerchant = null;
        $bestScore = 0.0;

        foreach ($merchants as $merchant) {
            $candidates = Collection::make([
                $merchant->normalized_name,
                $this->normalizeComparable($merchant->name),
            ])->filter()->unique();

            foreach ($candidates as $candidate) {
                $score = $this->similarity($normalizedName, $candidate);

                if ($score >= $this->fuzzyMatchThreshold && $score > $bestScore) {
                    $bestScore = $score;
                    $bestMerchant = $merchant;
                }
            }
        }

        return $bestMerchant;
    }

    protected function createMerchant(int $userId, string $displayName, string $normalizedName): Merchant
    {
        return Merchant::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'normalized_name' => $normalizedName,
            ],
            [
                'name' => $displayName,
                'website' => null,
                'type' => Merchant::OTHER,
                'supports_order_import' => false,
                'supports_api' => false,
                'metadata' => [],
            ],
        );
    }

    protected function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        similar_text($left, $right, $percent);

        return round($percent / 100, 4);
    }

    protected function normalizeComparable(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->squish()
            ->toString();
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
