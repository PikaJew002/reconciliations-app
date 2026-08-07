<?php

namespace App\Services\Reconciliation;

use App\Services\Reconciliation\Contracts\MerchantNameExtractor;
use Illuminate\Support\Str;

class BankMerchantNameExtractor implements MerchantNameExtractor
{
    /**
     * @var list<string>
     */
    protected array $cardPosPrefixes = [
        'dbt crd',
        'pos deb',
        'pos pur',
        'pos purchase',
        'debit card',
    ];

    /**
     * @var list<string>
     */
    protected array $stateAbbreviations = [
        'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'fl', 'ga',
        'hi', 'id', 'il', 'in', 'ia', 'ks', 'ky', 'la', 'me', 'md',
        'ma', 'mi', 'mn', 'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj',
        'nm', 'ny', 'nc', 'nd', 'oh', 'ok', 'or', 'pa', 'ri', 'sc',
        'sd', 'tn', 'tx', 'ut', 'vt', 'va', 'wa', 'wv', 'wi', 'wy',
        'dc',
    ];

    public function canExtract(string $normalizedDescription): bool
    {
        return $this->isCardPosDescription($normalizedDescription);
    }

    public function isCardPosDescription(string $normalizedDescription): bool
    {
        $description = $this->normalize($normalizedDescription);

        foreach ($this->cardPosPrefixes as $prefix) {
            if (str_starts_with($description, $prefix.' ') || $description === $prefix) {
                return true;
            }
        }

        return false;
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

        $locationTokens = $this->locationTokens($description);

        $merchantText = $this->stripBankNoise($description);
        $merchantText = $this->stripStoreAndReferenceNoise($merchantText);
        $merchantText = $this->stripAddressFragments($merchantText);
        $merchantText = preg_replace('/[-_\/]+/u', ' ', $merchantText) ?? $merchantText;
        $merchantText = preg_replace('/\b[a-z]\b/u', '', $merchantText) ?? $merchantText;
        $merchantText = $this->stripTrailingLocations($merchantText);
        $merchantText = $this->stripOrphanLocationTokens($merchantText, $locationTokens);
        $merchantText = $this->normalize($merchantText);

        // Trailing 1-2 letter scraps from truncated street names (e.g. "ma").
        if (count(explode(' ', $merchantText)) >= 3) {
            $merchantText = preg_replace('/\s+[a-z]{1,2}$/u', '', $merchantText) ?? $merchantText;
            $merchantText = $this->normalize($merchantText);
        }

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

    protected function stripBankNoise(string $description): string
    {
        $description = preg_replace('/\bc#\d{4}\b/u', '', $description) ?? $description;

        $prefixPattern = '/^(?:'.implode('|', array_map(
            static fn (string $prefix): string => preg_quote($prefix, '/'),
            $this->cardPosPrefixes,
        )).')\s+\d{3,4}\s+\d{2}\/\d{2}\/\d{2}\s+/u';

        $description = preg_replace($prefixPattern, '', $description) ?? $description;

        // Reference tokens immediately after the date (auth codes / POS ids).
        $description = preg_replace('/^[a-z0-9]{6,12}\s+/u', '', $description) ?? $description;

        return $this->normalize($description);
    }

    protected function stripTrailingLocations(string $text): string
    {
        $states = implode('|', $this->stateAbbreviations);

        if (! preg_match('/\b('.$states.')$/u', $text, $stateMatch)) {
            return $text;
        }

        // Only strip against the descriptor's trailing state. Intermediate
        // tokens like "ma" (street fragment) are also state codes.
        $homeState = $stateMatch[1];

        $multiWordCities = [
            'san francisco',
            'los angeles',
            'new york',
            'las vegas',
            'salt lake',
            'baton rouge',
            'oklahoma city',
            'kansas city',
            'des moines',
            'fort worth',
            'st louis',
            'st paul',
        ];

        foreach ($multiWordCities as $city) {
            $pattern = '/\s+'.preg_quote($city, '/').'\s+'.$homeState.'$/u';
            $next = preg_replace($pattern, '', $text);
            if ($next !== null && $next !== $text) {
                $text = $this->normalize($next);
            }
        }

        $singleCityPattern = '/\s+[a-z0-9.\']+\s+'.$homeState.'$/u';

        for ($i = 0; $i < 3; $i++) {
            $next = preg_replace($singleCityPattern, '', $text);
            if ($next === null || $next === $text) {
                break;
            }
            $text = $this->normalize($next);
        }

        return $text;
    }

    protected function stripStoreAndReferenceNoise(string $text): string
    {
        $text = preg_replace('/#\d+/u', '', $text) ?? $text;
        $text = preg_replace('/\b\d{4,}(?:--\d+)?\b/u', '', $text) ?? $text;
        $text = preg_replace('/\b[a-z]\d{3,}\b/u', '', $text) ?? $text;
        $text = preg_replace('/\b\d{3,}\b/u', '', $text) ?? $text;

        return $this->normalize($text);
    }

    protected function stripAddressFragments(string $text): string
    {
        $text = preg_replace(
            '/\b(?:road|rd|street|st|drive|dr|ave|avenue|blvd|highway|hwy|east|west|north|south|prince|royal)\b/u',
            '',
            $text,
        ) ?? $text;

        // Short airport-style prefixes seen in card descriptors.
        $text = preg_replace('/\b(?:dtw|dfw|lax|ord|atl|den|sea|sfo|jfk|las|phx|mia|bos|clt|msp)\s+/u', '', $text) ?? $text;

        return $this->normalize($text);
    }

    /**
     * @return list<string>
     */
    protected function locationTokens(string $description): array
    {
        $states = implode('|', $this->stateAbbreviations);
        preg_match_all('/\b([a-z0-9.\']+)\s+(?:'.$states.')\b/u', $description, $matches);

        $tokens = $matches[1] ?? [];

        preg_match_all('/\b([a-z0-9.\']+)\s+(?:road|rd|street|st|drive|dr|ave|avenue|blvd)\b/u', $description, $streetMatches);
        foreach ($streetMatches[1] ?? [] as $token) {
            $tokens[] = $token;
        }

        // Truncated city prefixes (e.g. "richmo" before "richmond ky").
        preg_match_all('/\b([a-z]{4,})\b(?=\s+[a-z0-9.\']+\s+(?:'.$states.')\b)/u', $description, $prefixMatches);

        foreach ($prefixMatches[1] ?? [] as $candidate) {
            foreach ($tokens as $token) {
                if (str_starts_with($token, $candidate) || str_starts_with($candidate, $token)) {
                    $tokens[] = $candidate;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $locationTokens
     */
    protected function stripOrphanLocationTokens(string $text, array $locationTokens): string
    {
        foreach ($locationTokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }

            $pattern = '/\s+'.preg_quote($token, '/').'$/u';
            $next = preg_replace($pattern, '', $text);
            if ($next !== null && $next !== $text) {
                $text = $this->normalize($next);
            }
        }

        return $text;
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
