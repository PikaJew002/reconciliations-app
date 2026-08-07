<?php

namespace Tests\Unit\Reconciliation;

use App\Services\Reconciliation\BankMerchantNameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankMerchantNameExtractorTest extends TestCase
{
    #[DataProvider('merchantDescriptions')]
    public function test_extracts_merchant_names_from_card_pos_descriptions(
        string $description,
        string $expectedNormalizedName,
    ): void {
        $extractor = new BankMerchantNameExtractor;
        $result = $extractor->extract($description);

        $this->assertNotNull($result);
        $this->assertSame($expectedNormalizedName, $result['normalized_name']);
    }

    public function test_returns_null_for_non_card_pos_descriptions(): void
    {
        $extractor = new BankMerchantNameExtractor;

        $this->assertNull($extractor->extract('venmo purchase 1051937135825'));
        $this->assertNull($extractor->extract('transfer from x6218 to x1758 amazon'));
        $this->assertFalse($extractor->isCardPosDescription('venmo purchase 1051937135825'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function merchantDescriptions(): array
    {
        return [
            'cook out' => [
                'dbt crd 1223 07/28/26 djr0ggqy cook out richmond ky richmond ky c#2525',
                'cook out',
            ],
            'circlek' => [
                'pos deb 1341 07/24/26 13424314 circlek #4703255 300 r 300 richmond road berea ky c#2195',
                'circlek',
            ],
            'taco bell' => [
                'dbt crd 2137 07/22/26 dja9sezi taco bell 021543 berea ky c#2195',
                'taco bell',
            ],
            'buc ee' => [
                'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
                'buc ee',
            ],
            'ace hardware' => [
                'dbt crd 1230 07/24/26 djwp2mm3 ace hardware-berea berea ky c#2195',
                'ace hardware',
            ],
            'cvs pharmacy' => [
                'pos deb 1143 07/20/26 00190112 cvs/pharmacy #06346 06346--409 east ma richmond ky c#2525',
                'cvs pharmacy',
            ],
        ];
    }
}
