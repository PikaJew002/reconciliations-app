<?php

namespace App\Services\Onboarding;

class OnboardingSteps
{
    public const IMPORT_BANK = 'import-bank';

    public const IMPORT_ORDERS = 'import-orders';

    /**
     * @return list<array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     cta: string,
     *     skippable: bool,
     *     tour: string,
     *     completeIf: callable(OnboardingSnapshot): bool,
     *     href: callable(OnboardingSnapshot): string
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => self::IMPORT_BANK,
                'title' => 'Import bank transactions',
                'description' => 'Upload about six weeks of history so you can see a full cycle of spend. Create an account first if you do not have one yet.',
                'cta' => 'Import CSV',
                'skippable' => false,
                'tour' => self::IMPORT_BANK,
                'completeIf' => fn (OnboardingSnapshot $snapshot): bool => $snapshot->hasBankImport,
                'href' => function (OnboardingSnapshot $snapshot): string {
                    if ($snapshot->firstAccountId === null) {
                        return '/accounts/create';
                    }

                    return "/accounts/{$snapshot->firstAccountId}/imports";
                },
            ],
            [
                'key' => self::IMPORT_ORDERS,
                'title' => 'Import Amazon or Walmart orders',
                'description' => 'Optional. Import about six weeks of order history so bank charges can be matched to line items. Skip if you do not shop at these retailers.',
                'cta' => 'Import orders',
                'skippable' => true,
                'tour' => self::IMPORT_ORDERS,
                'completeIf' => fn (OnboardingSnapshot $snapshot): bool => $snapshot->hasOrderImport,
                'href' => fn (OnboardingSnapshot $snapshot): string => '/orders',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function skippableKeys(): array
    {
        return collect($this->all())
            ->filter(fn (array $step): bool => $step['skippable'])
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tourKeys(): array
    {
        return collect($this->all())
            ->pluck('tour')
            ->unique()
            ->values()
            ->all();
    }
}
