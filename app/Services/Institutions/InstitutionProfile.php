<?php

namespace App\Services\Institutions;

use App\Services\Imports\Contracts\Importer;
use App\Services\Reconciliation\Contracts\MerchantNameExtractor;
use App\Services\Reconciliation\CreditCardMerchantNameExtractor;

final readonly class InstitutionProfile
{
    /**
     * @param  class-string<Importer>  $importerClass
     * @param  class-string<MerchantNameExtractor>  $extractorClass
     * @param  list<string>  $merchantSkipPatterns
     */
    public function __construct(
        public string $name,
        public string $importerClass,
        public string $extractorClass,
        public array $merchantSkipPatterns = [],
    ) {}

    public function makeExtractor(): MerchantNameExtractor
    {
        if ($this->extractorClass === CreditCardMerchantNameExtractor::class) {
            return new CreditCardMerchantNameExtractor($this->merchantSkipPatterns);
        }

        return app($this->extractorClass);
    }
}
