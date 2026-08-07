<?php

namespace App\Services\Reconciliation\Contracts;

interface MerchantNameExtractor
{
    /**
     * Whether this extractor can derive a merchant name from the description.
     */
    public function canExtract(string $normalizedDescription): bool;

    /**
     * @return array{display_name: string, normalized_name: string}|null
     */
    public function extract(string $normalizedDescription): ?array;
}
