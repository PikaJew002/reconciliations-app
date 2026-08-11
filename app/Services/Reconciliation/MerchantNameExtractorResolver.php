<?php

namespace App\Services\Reconciliation;

use App\Services\Institutions\InstitutionRegistry;
use App\Services\Reconciliation\Contracts\MerchantNameExtractor;

class MerchantNameExtractorResolver
{
    public function __construct(
        protected InstitutionRegistry $institutions,
    ) {}

    public function resolve(?string $institutionName): MerchantNameExtractor
    {
        if ($institutionName === null) {
            return app(BankMerchantNameExtractor::class);
        }

        $profile = $this->institutions->find($institutionName);

        if ($profile === null) {
            return app(BankMerchantNameExtractor::class);
        }

        return app($profile->extractorClass);
    }
}
