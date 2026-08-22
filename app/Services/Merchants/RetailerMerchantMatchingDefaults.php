<?php

namespace App\Services\Merchants;

use App\Models\Merchant;
use App\Models\MerchantMatchingRule;

class RetailerMerchantMatchingDefaults
{
    /**
     * @var array<string, list<string>>
     */
    public const PATTERNS = [
        'walmart' => [
            'wal-mart',
            'walmart',
            'wal mart',
            'wm supercenter',
            'walmart.com',
        ],
        'amazon' => [
            'amazon',
            'amzn',
            'amzn mktp',
            'amazon.com',
            'amazon mktpl',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function patternsFor(string $normalizedName): array
    {
        return self::PATTERNS[$normalizedName] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function allPatterns(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::PATTERNS))));
    }

    public static function isKnownRetailer(string $normalizedName): bool
    {
        return array_key_exists($normalizedName, self::PATTERNS);
    }

    /**
     * @return int Number of rules created.
     */
    public static function ensureForMerchant(Merchant $merchant): int
    {
        $patterns = self::patternsFor($merchant->normalized_name);

        if ($patterns === []) {
            return 0;
        }

        $created = 0;

        foreach ($patterns as $pattern) {
            $rule = MerchantMatchingRule::query()->firstOrCreate(
                [
                    'user_id' => $merchant->user_id,
                    'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                    'pattern' => $pattern,
                ],
                [
                    'merchant_id' => $merchant->id,
                    'is_active' => true,
                ],
            );

            if ($rule->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
