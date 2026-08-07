<?php

namespace App\Services\Reconciliation;

use App\Services\Reconciliation\Contracts\MerchantNameExtractor;
use Illuminate\Support\Str;

class CapitalOneMerchantNameExtractor implements MerchantNameExtractor
{
    /**
     * @var list<string>
     */
    protected array $skipPatterns = [
        'interest charge',
        'capital one mobile pymt',
        'credit-cash back',
        'payment thank you',
        'autopath',
    ];

    public function canExtract(string $normalizedDescription): bool
    {
        $description = $this->normalize($normalizedDescription);

        if ($description === '') {
            return false;
        }

        foreach ($this->skipPatterns as $pattern) {
            if (str_contains($description, $pattern)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{display_name: string, normalized_name: string}|null
     */
    public function extract(string $normalizedDescription): ?array
    {
        $description = $this->normalize($normalizedDescription);

        if ($description === '' || ! $this->canExtract($description)) {
            return null;
        }

        $merchantText = $this->stripDescriptorNoise($description);

        if ($merchantText === '' || mb_strlen($merchantText) < 2) {
            return null;
        }

        $normalizedName = $this->toNormalizedName($merchantText);

        if ($normalizedName === '' || mb_strlen($normalizedName) < 2) {
            return null;
        }

        return [
            'display_name' => $this->toDisplayName($normalizedName),
            'normalized_name' => $normalizedName,
        ];
    }

    protected function stripDescriptorNoise(string $description): string
    {
        // Processor / network prefixes: "SQ *TUMBLE...", "GOOGLE *Google One", "HLU*HULUPLUS".
        $description = preg_replace('/^[a-z0-9]+\s*\*\s*/u', '', $description) ?? $description;
        $description = str_replace('*', ' ', $description);

        // Domains and paths: "apple.com/bill", "digitalocean.com", "netflix.com".
        $description = preg_replace('/\.(?:com|net|org|io)(?:\/\S*)?/u', ' ', $description) ?? $description;

        // Store numbers and trailing location codes: "#1190", "021543".
        $description = preg_replace('/#\d+/u', '', $description) ?? $description;
        $description = preg_replace('/\b\d{4,}\b/u', '', $description) ?? $description;

        // Collapse punctuation that is not useful for matching.
        $description = preg_replace('/[-_\/]+/u', ' ', $description) ?? $description;

        return $this->normalize($description);
    }

    protected function toNormalizedName(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->squish()
            ->toString();
    }

    protected function toDisplayName(string $normalizedName): string
    {
        return Str::title($normalizedName);
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }
}
