<?php

namespace Tests\Unit\Institutions;

use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Institutions\InstitutionRegistry;
use App\Services\Reconciliation\BankMerchantNameExtractor;
use App\Services\Reconciliation\CapitalOneMerchantNameExtractor;
use App\Services\Reconciliation\CumberlandValleyCreditCardMerchantNameExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstitutionRegistryTest extends TestCase
{
    #[Test]
    public function it_lists_registered_institution_names_in_order(): void
    {
        $registry = app(InstitutionRegistry::class);

        $this->assertSame([
            CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME,
        ], $registry->names());
    }

    #[Test]
    public function it_resolves_importer_and_extractor_classes_per_institution(): void
    {
        $registry = app(InstitutionRegistry::class);

        $capitalOne = $registry->get(CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME);
        $this->assertSame(CapitalOneCreditCardTransactionImporter::class, $capitalOne->importerClass);
        $this->assertSame(CapitalOneMerchantNameExtractor::class, $capitalOne->extractorClass);

        $cvnb = $registry->get(CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        $this->assertSame(CumberlandValleyNationalBankTransactionImporter::class, $cvnb->importerClass);
        $this->assertSame(BankMerchantNameExtractor::class, $cvnb->extractorClass);

        $cvnbCard = $registry->get(CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME);
        $this->assertSame(CumberlandValleyCreditCardTransactionImporter::class, $cvnbCard->importerClass);
        $this->assertSame(CumberlandValleyCreditCardMerchantNameExtractor::class, $cvnbCard->extractorClass);
    }

    #[Test]
    public function it_returns_null_for_unknown_institutions_and_throws_on_get(): void
    {
        $registry = app(InstitutionRegistry::class);

        $this->assertNull($registry->find('Unknown Bank'));

        $this->expectException(InvalidArgumentException::class);
        $registry->get('Unknown Bank');
    }
}
