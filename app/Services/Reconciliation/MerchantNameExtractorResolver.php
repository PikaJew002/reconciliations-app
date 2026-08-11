<?php

namespace App\Services\Reconciliation;

use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Reconciliation\Contracts\MerchantNameExtractor;

class MerchantNameExtractorResolver
{
    public function resolve(?string $institutionName): MerchantNameExtractor
    {
        return match ($institutionName) {
            CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME => app(
                CapitalOneMerchantNameExtractor::class,
            ),
            CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME => app(
                CumberlandValleyCreditCardMerchantNameExtractor::class,
            ),
            CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME => app(
                BankMerchantNameExtractor::class,
            ),
            default => app(BankMerchantNameExtractor::class),
        };
    }
}
