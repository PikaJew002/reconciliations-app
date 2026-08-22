<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Merchants\RetailerMerchantMatchingDefaults;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_walmart_descriptions_to_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        RetailerMerchantMatchingDefaults::ensureForMerchant($merchant);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -71.98,
            'description' => 'POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA KY C#2195',
            'normalized_description' => 'pos deb 1716 04/29/26 40269900 wal-mart #1190 120 jill dr berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertFalse($matcher->matchTransaction($transaction->fresh(), $user->id));
    }

    public function test_does_not_create_walmart_merchant_when_missing(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -20.00,
            'description' => 'POS DEB 1716 04/29/26 40269900 WAL-MART #1190 BEREA KY C#2195',
            'normalized_description' => 'pos deb 1716 04/29/26 40269900 wal-mart #1190 berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_does_not_create_amazon_merchant_when_missing(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -42.10,
            'description' => 'DBT CRD 0848 07/22/26 DJJKQM32 AMAZON MKTPL*XE7F71XN3 SEATTLE WA C#2195',
            'normalized_description' => 'dbt crd 0848 07/22/26 djjkqm32 amazon mktpl*xe7f71xn3 seattle wa c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_matches_amazon_descriptions_to_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        RetailerMerchantMatchingDefaults::ensureForMerchant($merchant);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -42.10,
            'description' => 'DBT CRD 0848 07/22/26 DJJKQM32 AMAZON MKTPL*XE7F71XN3 SEATTLE WA C#2195',
            'normalized_description' => 'dbt crd 0848 07/22/26 djjkqm32 amazon mktpl*xe7f71xn3 seattle wa c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
    }

    public function test_skips_venmo_and_transfer_noise(): void
    {
        $user = User::factory()->create();
        Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $venmo = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -14.15,
            'description' => 'VENMO PURCHASE 1051937135825',
            'normalized_description' => 'venmo purchase 1051937135825',
            'status' => 'unmatched',
        ]);

        $transfer = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -50.00,
            'description' => 'TRANSFER FROM X6218 TO X1758',
            'normalized_description' => 'transfer from x6218 to x1758',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($venmo, $user->id));
        $this->assertFalse($matcher->matchTransaction($transfer, $user->id));
        $this->assertNull($venmo->fresh()->merchant_id);
        $this->assertNull($transfer->fresh()->merchant_id);
    }

    public function test_creates_merchant_for_card_pos_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -12.25,
            'description' => 'DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525',
            'normalized_description' => 'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));

        $merchant = Merchant::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($merchant);
        $this->assertSame('buc ee', $merchant->normalized_name);
        $this->assertFalse($merchant->supports_order_import);
        $this->assertSame(Merchant::OTHER, $merchant->type);
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => 'extracted_name',
            'pattern' => 'buc ee',
        ]);
    }

    public function test_creates_merchant_for_capital_one_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -27.12,
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));

        $merchant = Merchant::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($merchant);
        $this->assertSame('taco bell', $merchant->normalized_name);
        $this->assertFalse($merchant->supports_order_import);
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => 'extracted_name',
            'pattern' => 'taco bell',
        ]);
    }

    public function test_does_not_create_merchant_for_capital_one_interest_charge(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -271.04,
            'description' => 'INTEREST CHARGE:PURCHASES',
            'normalized_description' => 'interest charge:purchases',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_fuzzy_matches_similar_card_pos_descriptions_to_existing_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Circlek',
            'normalized_name' => 'circlek',
            'supports_order_import' => false,
            'type' => Merchant::OTHER,
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -30.42,
            'description' => 'POS DEB 1343 07/24/26 13437780 CIRCLEK #4703255 300 R 300 RICHMOND ROAD BEREA KY C#2195',
            'normalized_description' => 'pos deb 1343 07/24/26 13437780 circlek #4703255 300 r 300 richmond road berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => 'extracted_name',
            'pattern' => 'circlek',
        ]);
    }

    public function test_user_contains_rule_matches_before_fuzzy_create(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Buc-ee\'s',
            'normalized_name' => 'buc ees',
            'supports_order_import' => false,
        ]);
        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'buc-ee',
        ]);
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -12.25,
            'description' => 'DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525',
            'normalized_description' => 'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_extracted_name_rule_matches_without_fuzzy(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Taco Bell',
            'normalized_name' => 'taco bell restaurants',
            'supports_order_import' => false,
        ]);
        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'taco bell',
        ]);
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -8.50,
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_inactive_rule_does_not_match(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Target',
            'normalized_name' => 'target',
            'supports_order_import' => false,
        ]);
        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target',
            'is_active' => false,
        ]);
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -20.00,
            'description' => 'TARGET MEMBERSHIP FEE',
            'normalized_description' => 'target membership fee',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
    }

    public function test_does_not_steal_extracted_name_rule_owned_by_another_merchant(): void
    {
        $user = User::factory()->create();
        $owner = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Circle K',
            'normalized_name' => 'circle k',
            'supports_order_import' => false,
        ]);
        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $owner->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'circlek',
        ]);
        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Circlek',
            'normalized_name' => 'circlek',
            'supports_order_import' => false,
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -30.42,
            'description' => 'POS DEB 1343 07/24/26 13437780 CIRCLEK #4703255 300 R 300 RICHMOND ROAD BEREA KY C#2195',
            'normalized_description' => 'pos deb 1343 07/24/26 13437780 circlek #4703255 300 r 300 richmond road berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($owner->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseHas('merchant_matching_rules', [
            'merchant_id' => $owner->id,
            'match_mode' => 'extracted_name',
            'pattern' => 'circlek',
        ]);
        $this->assertEquals(1, MerchantMatchingRule::query()->where('pattern', 'circlek')->count());
        $this->assertSame(2, Merchant::query()->where('user_id', $user->id)->count());
        $this->assertNotNull($other->fresh());
    }
}
