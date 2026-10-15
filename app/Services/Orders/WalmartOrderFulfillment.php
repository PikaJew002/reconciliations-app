<?php

namespace App\Services\Orders;

class WalmartOrderFulfillment
{
    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const PICKUP = 'pickup';

    public const IN_STORE = 'in_store';

    /**
     * @var array<string, string>
     */
    public const FILTERS = [
        self::SHIPPED => 'Shipped',
        self::DELIVERED => 'Delivered',
        self::PICKUP => 'Picked up',
        self::IN_STORE => 'In-store',
    ];

    /**
     * @var array<string, string>
     */
    public const TOKENS = [
        self::SHIPPED => 'FC_DELIVERY',
        self::DELIVERED => 'SC_DELIVERY',
        self::PICKUP => 'SC_PICKUP',
        self::IN_STORE => 'IN_STORE',
    ];

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return list<string>
     */
    public static function types(?array $metadata): array
    {
        $tokens = self::tokens($metadata['fulfillmentTypes'] ?? null);
        $types = [];

        foreach (self::TOKENS as $type => $token) {
            if (in_array($token, $tokens, true)) {
                $types[] = $type;
            }
        }

        if (! in_array(self::IN_STORE, $types, true) && ($metadata['orderType'] ?? null) === 'IN_STORE') {
            $types[] = self::IN_STORE;
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return list<string>
     */
    public static function labels(?array $metadata): array
    {
        return array_map(
            fn (string $type): string => self::FILTERS[$type],
            self::types($metadata),
        );
    }

    /**
     * @return list<string>
     */
    public static function tokens(mixed $fulfillmentTypes): array
    {
        if (is_array($fulfillmentTypes)) {
            $parts = $fulfillmentTypes;
        } else {
            $raw = trim((string) $fulfillmentTypes);
            $parts = $raw === '' ? [] : (preg_split('/\s*,\s*/', $raw) ?: []);
        }

        $tokens = [];

        foreach ($parts as $part) {
            $token = strtoupper(trim((string) $part));

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }
}
