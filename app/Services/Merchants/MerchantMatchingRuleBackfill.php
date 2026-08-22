<?php

namespace App\Services\Merchants;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Support\Facades\Log;

class MerchantMatchingRuleBackfill
{
    public function __construct(
        protected MerchantMatcher $matcher,
    ) {}

    /**
     * @return array{users: int, rules_created: int, unexplained: int, collisions: int}
     */
    public function backfill(?int $userId = null): array
    {
        $merchantQuery = Merchant::query()->orderBy('id');

        if ($userId !== null) {
            $merchantQuery->where('user_id', $userId);
        }

        $userIds = $merchantQuery->clone()
            ->distinct()
            ->pluck('user_id');

        $rulesCreated = 0;
        $unexplained = 0;
        $collisions = 0;

        foreach ($userIds as $currentUserId) {
            $rulesCreated += $this->ensureRetailerDefaults((int) $currentUserId);

            $result = $this->backfillUser((int) $currentUserId);
            $rulesCreated += $result['rules_created'];
            $unexplained += $result['unexplained'];
            $collisions += $result['collisions'];
        }

        return [
            'users' => $userIds->count(),
            'rules_created' => $rulesCreated,
            'unexplained' => $unexplained,
            'collisions' => $collisions,
        ];
    }

    /**
     * @return array{rules_created: int, unexplained: int, collisions: int}
     */
    protected function backfillUser(int $userId): array
    {
        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->whereHas('bankTransactions')
            ->with(['bankTransactions.account'])
            ->orderBy('id')
            ->get();

        $rulesCreated = 0;
        $collisions = 0;
        $unexplained = 0;

        foreach ($merchants as $merchant) {
            foreach ($merchant->bankTransactions as $transaction) {
                $derived = $this->deriveRulesForAssignment($merchant, $transaction);
                $rulesCreated += $derived['created'];
                $collisions += $derived['collisions'];
            }
        }

        foreach ($merchants as $merchant) {
            foreach ($merchant->bankTransactions as $transaction) {
                $matched = $this->matcher->findMerchantByRules($transaction, $userId);

                if ($matched?->id === $merchant->id) {
                    continue;
                }

                $unexplained++;

                Log::warning('Merchant matching rule backfill could not explain transaction', [
                    'user_id' => $userId,
                    'transaction_id' => $transaction->id,
                    'merchant_id' => $merchant->id,
                    'description' => $transaction->description,
                ]);
            }
        }

        return [
            'rules_created' => $rulesCreated,
            'unexplained' => $unexplained,
            'collisions' => $collisions,
        ];
    }

    /**
     * @return array{created: int, collisions: int}
     */
    protected function deriveRulesForAssignment(Merchant $merchant, BankTransaction $transaction): array
    {
        $created = 0;
        $collisions = 0;
        $description = $this->matcher->normalizedDescription($transaction);

        foreach (RetailerMerchantMatchingDefaults::PATTERNS as $normalizedName => $patterns) {
            if ($merchant->normalized_name !== $normalizedName) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (! str_contains($description, $pattern)) {
                    continue;
                }

                $result = $this->persistRule(
                    $merchant,
                    $transaction,
                    MerchantMatchingRule::MATCH_CONTAINS,
                    $pattern,
                );
                $created += $result['created'];
                $collisions += $result['collision'] ? 1 : 0;
            }
        }

        $extracted = $this->matcher->extractName($transaction, $description);

        if ($extracted !== null
            && $this->matcher->extractedNameMatchesMerchant($merchant, $extracted['normalized_name'])) {
            $result = $this->persistRule(
                $merchant,
                $transaction,
                MerchantMatchingRule::MATCH_EXTRACTED_NAME,
                $extracted['normalized_name'],
            );
            $created += $result['created'];
            $collisions += $result['collision'] ? 1 : 0;
        }

        return [
            'created' => $created,
            'collisions' => $collisions,
        ];
    }

    /**
     * @return array{created: int, collision: bool}
     */
    protected function persistRule(
        Merchant $merchant,
        BankTransaction $transaction,
        string $matchMode,
        string $pattern,
    ): array {
        $pattern = MerchantMatchingRule::normalizePattern($pattern);

        if ($pattern === '') {
            return ['created' => 0, 'collision' => false];
        }

        $existing = MerchantMatchingRule::query()
            ->where('user_id', $merchant->user_id)
            ->where('match_mode', $matchMode)
            ->where('pattern', $pattern)
            ->first();

        if ($existing !== null && $existing->merchant_id !== $merchant->id) {
            Log::warning('Merchant matching rule backfill skipped pattern owned by another merchant', [
                'user_id' => $merchant->user_id,
                'transaction_id' => $transaction->id,
                'merchant_id' => $merchant->id,
                'existing_merchant_id' => $existing->merchant_id,
                'match_mode' => $matchMode,
                'pattern' => $pattern,
                'description' => $transaction->description,
            ]);

            return ['created' => 0, 'collision' => true];
        }

        if ($existing !== null) {
            return ['created' => 0, 'collision' => false];
        }

        MerchantMatchingRule::query()->create([
            'user_id' => $merchant->user_id,
            'merchant_id' => $merchant->id,
            'match_mode' => $matchMode,
            'pattern' => $pattern,
            'is_active' => true,
        ]);

        return ['created' => 1, 'collision' => false];
    }

    protected function ensureRetailerDefaults(int $userId): int
    {
        $created = 0;

        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->whereIn('normalized_name', array_keys(RetailerMerchantMatchingDefaults::PATTERNS))
            ->get();

        foreach ($merchants as $merchant) {
            $created += RetailerMerchantMatchingDefaults::ensureForMerchant($merchant);
        }

        return $created;
    }
}
