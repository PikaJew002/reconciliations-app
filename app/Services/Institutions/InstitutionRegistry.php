<?php

namespace App\Services\Institutions;

use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Reconciliation\BankMerchantNameExtractor;
use App\Services\Reconciliation\CreditCardMerchantNameExtractor;
use InvalidArgumentException;

class InstitutionRegistry
{
    /**
     * @var list<InstitutionProfile>|null
     */
    protected ?array $profiles = null;

    /**
     * @return list<InstitutionProfile>
     */
    public function all(): array
    {
        return $this->profiles ??= [
            new InstitutionProfile(
                name: CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
                importerClass: CapitalOneCreditCardTransactionImporter::class,
                extractorClass: CreditCardMerchantNameExtractor::class,
                merchantSkipPatterns: [
                    'interest charge',
                    'capital one mobile pymt',
                    'credit-cash back',
                    'payment thank you',
                    'autopath',
                ],
            ),
            new InstitutionProfile(
                name: CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
                importerClass: CumberlandValleyNationalBankTransactionImporter::class,
                extractorClass: BankMerchantNameExtractor::class,
            ),
            new InstitutionProfile(
                name: CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME,
                importerClass: CumberlandValleyCreditCardTransactionImporter::class,
                extractorClass: CreditCardMerchantNameExtractor::class,
                merchantSkipPatterns: [
                    'interest charge',
                    'payment adjustment',
                    'payment thank you',
                    'online payment',
                    'credit card payment',
                ],
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn (InstitutionProfile $profile): string => $profile->name,
            $this->all(),
        );
    }

    public function find(string $name): ?InstitutionProfile
    {
        foreach ($this->all() as $profile) {
            if ($profile->name === $name) {
                return $profile;
            }
        }

        return null;
    }

    public function get(string $name): InstitutionProfile
    {
        $profile = $this->find($name);

        if ($profile === null) {
            throw new InvalidArgumentException("No institution registered for [{$name}].");
        }

        return $profile;
    }
}
