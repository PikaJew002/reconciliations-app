<?php

namespace Tests\Unit\Reconciliation;

use App\Services\Reconciliation\CapitalOneMerchantNameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CapitalOneMerchantNameExtractorTest extends TestCase
{
    #[DataProvider('merchantDescriptions')]
    public function test_extracts_merchant_names_from_capital_one_descriptions(
        string $description,
        string $expectedNormalizedName,
    ): void {
        $extractor = new CapitalOneMerchantNameExtractor;
        $result = $extractor->extract($description);

        $this->assertNotNull($result);
        $this->assertSame($expectedNormalizedName, $result['normalized_name']);
    }

    public function test_skips_interest_and_payment_descriptors(): void
    {
        $extractor = new CapitalOneMerchantNameExtractor;

        $this->assertFalse($extractor->canExtract('interest charge:purchases'));
        $this->assertFalse($extractor->canExtract('capital one mobile pymt'));
        $this->assertFalse($extractor->canExtract('credit-cash back reward'));
        $this->assertNull($extractor->extract('interest charge:purchases'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function merchantDescriptions(): array
    {
        return [
            'taco bell' => ['taco bell 021543', 'taco bell'],
            'meijer' => ['meijer store #258', 'meijer store'],
            'laravel cloud' => ['laravel cloud', 'laravel cloud'],
            'apple bill' => ['apple.com/bill', 'apple'],
            'walmart' => ['wal-mart #1190', 'wal mart'],
            'hulu' => ['hlu*huluplus', 'huluplus'],
            'square' => ['sq *tumble shine athle', 'tumble shine athle'],
            'google one' => ['google *google one', 'google one'],
            'lifestance' => ['phr*lifestancehealth', 'lifestancehealth'],
            'digitalocean' => ['digitalocean.com', 'digitalocean'],
            'netflix' => ['netflix.com', 'netflix'],
            'cloudflare' => ['cloudflare', 'cloudflare'],
        ];
    }
}
