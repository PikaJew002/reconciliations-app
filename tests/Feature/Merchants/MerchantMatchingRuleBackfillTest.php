<?php

namespace Tests\Feature\Merchants;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Merchants\MerchantMatchingRuleBackfill;
use App\Services\Merchants\RetailerMerchantMatchingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MerchantMatchingRuleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_creates_one_contains_rule_per_retailer_pattern(): void
    {
        $user = User::factory()->create();
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);

        $created = RetailerMerchantMatchingDefaults::ensureForMerchant($walmart);

        $this->assertSame(5, $created);
        $this->assertEqualsCanonicalizing(
            RetailerMerchantMatchingDefaults::patternsFor('walmart'),
            $walmart->matchingRules()->pluck('pattern')->all(),
        );
        $this->assertSame(0, RetailerMerchantMatchingDefaults::ensureForMerchant($walmart));
    }

    public function test_backfill_seeds_retailer_defaults_and_extracted_name_rules(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);

        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $amazon->id,
            'amount' => -12.00,
            'description' => 'AMZN MKTP US',
            'normalized_description' => 'amzn mktp us',
        ]);

        $tacoBell = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Taco Bell',
            'normalized_name' => 'taco bell',
            'supports_order_import' => false,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $tacoBell->id,
            'amount' => -8.50,
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
        ]);

        $result = app(MerchantMatchingRuleBackfill::class)->backfill($user->id);

        $this->assertSame(1, $result['users']);
        $this->assertSame(0, $result['unexplained']);
        $this->assertGreaterThanOrEqual(6, $result['rules_created']);

        foreach (RetailerMerchantMatchingDefaults::patternsFor('amazon') as $pattern) {
            $this->assertDatabaseHas('merchant_matching_rules', [
                'user_id' => $user->id,
                'merchant_id' => $amazon->id,
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => $pattern,
            ]);
        }

        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $tacoBell->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'taco bell',
        ]);
    }

    public function test_backfill_logs_unexplained_transactions(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Mystery Payee',
            'normalized_name' => 'mystery payee',
            'supports_order_import' => false,
        ]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -40.00,
            'description' => 'CHECK 1044',
            'normalized_description' => 'check 1044',
        ]);

        $result = app(MerchantMatchingRuleBackfill::class)->backfill($user->id);

        $this->assertSame(1, $result['unexplained']);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($user, $transaction, $merchant) {
            return $message === 'Merchant matching rule backfill could not explain transaction'
                && ($context['user_id'] ?? null) === $user->id
                && ($context['transaction_id'] ?? null) === $transaction->id
                && ($context['merchant_id'] ?? null) === $merchant->id
                && ($context['description'] ?? null) === 'CHECK 1044';
        })->once();
    }

    public function test_backfill_does_not_steal_pattern_owned_by_another_merchant(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);

        $owner = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Taco Bell',
            'normalized_name' => 'taco bell',
            'supports_order_import' => false,
        ]);
        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $owner->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'taco bell',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $owner->id,
            'amount' => -8.50,
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
        ]);

        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Taco Bell',
            'normalized_name' => 'taco bell downtown',
            'supports_order_import' => false,
        ]);
        $colliding = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $other->id,
            'amount' => -9.00,
            'description' => 'TACO BELL 999999',
            'normalized_description' => 'taco bell 999999',
        ]);

        $result = app(MerchantMatchingRuleBackfill::class)->backfill($user->id);

        $this->assertSame(1, MerchantMatchingRule::query()->where('pattern', 'taco bell')->count());
        $this->assertSame($owner->id, MerchantMatchingRule::query()->where('pattern', 'taco bell')->value('merchant_id'));
        $this->assertGreaterThanOrEqual(1, $result['collisions']);
        $this->assertGreaterThanOrEqual(1, $result['unexplained']);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($colliding, $other) {
            return $message === 'Merchant matching rule backfill skipped pattern owned by another merchant'
                && ($context['transaction_id'] ?? null) === $colliding->id
                && ($context['merchant_id'] ?? null) === $other->id;
        });
    }
}
