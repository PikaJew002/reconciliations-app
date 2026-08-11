<?php

namespace Tests\Unit\Reconciliation;

use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyCreditCardTransactionImporter;
use App\Services\Institutions\InstitutionRegistry;
use App\Services\Reconciliation\CreditCardMerchantNameExtractor;
use App\Services\Reconciliation\MerchantNameExtractorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardMerchantNameExtractorTest extends TestCase
{
    #[DataProvider('merchantDescriptions')]
    public function test_extracts_merchant_names_from_credit_card_descriptions(
        string $description,
        string $expectedNormalizedName,
    ): void {
        $extractor = new CreditCardMerchantNameExtractor([
            'interest charge',
            'capital one mobile pymt',
            'credit-cash back',
            'payment thank you',
            'autopath',
        ]);
        $result = $extractor->extract($description);

        $this->assertNotNull($result);
        $this->assertSame($expectedNormalizedName, $result['normalized_name']);
    }

    #[Test]
    public function it_skips_capital_one_interest_and_payment_descriptors(): void
    {
        $extractor = app(MerchantNameExtractorResolver::class)
            ->resolve(CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME);

        $this->assertInstanceOf(CreditCardMerchantNameExtractor::class, $extractor);
        $this->assertFalse($extractor->canExtract('interest charge:purchases'));
        $this->assertFalse($extractor->canExtract('capital one mobile pymt'));
        $this->assertFalse($extractor->canExtract('credit-cash back reward'));
        $this->assertNull($extractor->extract('interest charge:purchases'));
    }

    #[Test]
    public function it_skips_cumberland_valley_credit_card_payment_descriptors(): void
    {
        $extractor = app(MerchantNameExtractorResolver::class)
            ->resolve(CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME);

        $this->assertInstanceOf(CreditCardMerchantNameExtractor::class, $extractor);
        $this->assertFalse($extractor->canExtract('interest charge'));
        $this->assertFalse($extractor->canExtract('payment adjustment'));
        $this->assertFalse($extractor->canExtract('online payment thank you'));
        $this->assertFalse($extractor->canExtract('credit card payment'));
        $this->assertNull($extractor->extract('payment thank you'));
    }

    #[Test]
    public function registry_profiles_supply_skip_patterns_to_the_shared_extractor(): void
    {
        $registry = app(InstitutionRegistry::class);

        $capitalOne = $registry->get(CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME);
        $this->assertSame(CreditCardMerchantNameExtractor::class, $capitalOne->extractorClass);
        $this->assertContains('capital one mobile pymt', $capitalOne->merchantSkipPatterns);

        $cvnbCard = $registry->get(CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME);
        $this->assertSame(CreditCardMerchantNameExtractor::class, $cvnbCard->extractorClass);
        $this->assertContains('payment adjustment', $cvnbCard->merchantSkipPatterns);
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
