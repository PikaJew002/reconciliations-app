<?php

namespace App\Services\Institutions;

use App\Services\Imports\Contracts\Importer;
use App\Services\Reconciliation\Contracts\MerchantNameExtractor;

final readonly class InstitutionProfile
{
    /**
     * @param  class-string<Importer>  $importerClass
     * @param  class-string<MerchantNameExtractor>  $extractorClass
     */
    public function __construct(
        public string $name,
        public string $importerClass,
        public string $extractorClass,
    ) {}
}
