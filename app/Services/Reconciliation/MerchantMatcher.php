<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Merchants\RetailerMerchantMatchingDefaults;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MerchantMatcher
{
    public function __construct(
        protected MerchantNameExtractorResolver $extractorResolver,
        protected float $fuzzyMatchThreshold = 0.85,
    ) {}

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
            ->availableForExpenseMatching()
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

        $description = $this->normalizedDescription($transaction);

        if ($this->shouldSkipDescription($description)) {
            return false;
        }

        $ruleMerchant = $this->findMerchantByRules($transaction, $userId, $description);

        if ($ruleMerchant !== null) {
            $transaction->update(['merchant_id' => $ruleMerchant->id]);

            return true;
        }

        if ((float) $transaction->amount >= 0) {
            return false;
        }

        $extracted = $this->extractName($transaction, $description);

        if ($extracted === null) {
            return false;
        }

        if ($this->looksLikeOrderImportRetailer($description)
            || $this->looksLikeOrderImportRetailer($extracted['normalized_name'])) {
            return false;
        }

        $merchant = $this->findFuzzyMerchant($userId, $extracted['normalized_name'])
            ?? $this->createMerchant($userId, $extracted['display_name'], $extracted['normalized_name']);

        $this->learnExtractedNameRule($userId, $merchant, $extracted['normalized_name']);

        $transaction->update(['merchant_id' => $merchant->id]);

        return true;
    }

    public function findMerchantByRules(
        BankTransaction $transaction,
        int $userId,
        ?string $description = null,
    ): ?Merchant {
        $description ??= $this->normalizedDescription($transaction);

        $containsMerchant = $this->findMerchantByContainsRules($userId, $description);

        if ($containsMerchant !== null) {
            return $containsMerchant;
        }

        $extracted = $this->extractName($transaction, $description);

        if ($extracted === null) {
            return null;
        }

        return $this->findMerchantByExtractedNameRule($userId, $extracted['normalized_name']);
    }

    public function extractedNameMatchesMerchant(Merchant $merchant, string $extractedNormalizedName): bool
    {
        if ($merchant->normalized_name === $extractedNormalizedName) {
            return true;
        }

        $candidates = Collection::make([
            $merchant->normalized_name,
            $this->normalizeComparable($merchant->name),
        ])->filter()->unique();

        foreach ($candidates as $candidate) {
            if ($this->similarity($extractedNormalizedName, $candidate) >= $this->fuzzyMatchThreshold) {
                return true;
            }
        }

        return false;
    }

    public function normalizedDescription(BankTransaction $transaction): string
    {
        if (filled($transaction->normalized_description)) {
            return (string) $transaction->normalized_description;
        }

        return Str::of((string) $transaction->description)->lower()->squish()->toString();
    }

    /**
     * @return array{display_name: string, normalized_name: string}|null
     */
    public function extractName(BankTransaction $transaction, ?string $description = null): ?array
    {
        $description ??= $this->normalizedDescription($transaction);

        $transaction->loadMissing('account');
        $extractor = $this->extractorResolver->resolve($transaction->account?->institution_name);

        if (! $extractor->canExtract($description)) {
            return null;
        }

        return $extractor->extract($description);
    }

    public function learnExtractedNameRule(int $userId, Merchant $merchant, string $pattern): void
    {
        $pattern = MerchantMatchingRule::normalizePattern($pattern);

        if ($pattern === '') {
            return;
        }

        MerchantMatchingRule::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
                'pattern' => $pattern,
            ],
            [
                'merchant_id' => $merchant->id,
                'is_active' => true,
            ],
        );
    }

    protected function findMerchantByContainsRules(int $userId, string $description): ?Merchant
    {
        $rules = MerchantMatchingRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('match_mode', MerchantMatchingRule::MATCH_CONTAINS)
            ->with('merchant')
            ->orderByRaw('LENGTH(pattern) DESC')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->pattern !== '' && str_contains($description, $rule->pattern)) {
                return $rule->merchant;
            }
        }

        return null;
    }

    protected function findMerchantByExtractedNameRule(int $userId, string $extractedNormalizedName): ?Merchant
    {
        $rule = MerchantMatchingRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('match_mode', MerchantMatchingRule::MATCH_EXTRACTED_NAME)
            ->where('pattern', $extractedNormalizedName)
            ->with('merchant')
            ->first();

        return $rule?->merchant;
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

    protected function looksLikeOrderImportRetailer(string $value): bool
    {
        return $this->descriptionMatches($value, RetailerMerchantMatchingDefaults::allPatterns());
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
        $merchant = Merchant::query()->firstOrCreate(
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

        $this->learnExtractedNameRule($userId, $merchant, $normalizedName);

        return $merchant;
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
