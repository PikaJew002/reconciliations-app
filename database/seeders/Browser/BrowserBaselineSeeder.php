<?php

namespace Database\Seeders\Browser;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\User;

class BrowserBaselineSeeder extends BrowserScenarioSeeder
{
    public const CHECKING_EXTERNAL_ID = 'browser:checking';

    public const CREDIT_CARD_EXTERNAL_ID = 'browser:credit-card';

    public function run(User $user): void
    {
        Account::factory()->for($user)->create([
            'name' => 'Checking',
            'institution_name' => 'Cumberland Valley National Bank',
            'account_name' => 'Checking',
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'USD',
            'last_four' => '1001',
            'external_id' => self::CHECKING_EXTERNAL_ID,
            'is_active' => true,
        ]);

        Account::factory()->for($user)->create([
            'name' => 'Credit Card',
            'institution_name' => 'Capital One',
            'account_name' => 'Credit Card',
            'account_type' => Account::CREDIT_CARD,
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'USD',
            'last_four' => '2002',
            'external_id' => self::CREDIT_CARD_EXTERNAL_ID,
            'is_active' => true,
        ]);

        Category::findOrCreateForUser($user->id, Category::KIND_BILL, 'Rent');
        Category::findOrCreateForUser($user->id, Category::KIND_EXPENSE, 'Groceries');
        Category::findOrCreateForUser($user->id, Category::KIND_INCOME, 'Paycheck');

        $user->forceFill([
            'onboarding_hidden_at' => now(),
        ])->save();
    }
}
