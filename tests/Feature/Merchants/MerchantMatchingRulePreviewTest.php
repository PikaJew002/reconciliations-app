<?php

namespace Tests\Feature\Merchants;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantMatchingRulePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_contains_rule_reports_conflicts_misses_and_unassigned_hits(): void
    {
        [$user, $merchant, $account] = $this->merchantWithActivity();

        $covered = BankTransaction::query()
            ->where('merchant_id', $merchant->id)
            ->first();
        $covered->update([
            'description' => 'STARBUCKS STORE 1',
            'normalized_description' => 'starbucks store 1',
        ]);

        $missed = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-02',
            'amount' => -3.25,
            'description' => 'COFFEE SHOP DOWNTOWN',
            'normalized_description' => 'coffee shop downtown',
        ]);

        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Other Coffee',
            'normalized_name' => 'other coffee',
            'supports_order_import' => false,
        ]);
        $conflict = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $other->id,
            'posted_at' => '2026-08-03',
            'amount' => -4.50,
            'description' => 'STARBUCKS 123',
            'normalized_description' => 'starbucks 123',
        ]);

        $unassigned = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'posted_at' => '2026-08-04',
            'amount' => -9.99,
            'description' => 'SQ *STARBUCKS STORE',
            'normalized_description' => 'sq *starbucks store',
        ]);

        $otherUser = User::factory()->create();
        BankTransaction::factory()->create([
            'user_id' => $otherUser->id,
            'merchant_id' => null,
            'description' => 'STARBUCKS OTHER USER',
            'normalized_description' => 'starbucks other user',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('merchants.rules.check', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'Starbucks',
            ])
            ->assertOk()
            ->assertJsonPath('pattern', 'starbucks')
            ->assertJsonPath('match_mode', MerchantMatchingRule::MATCH_CONTAINS)
            ->assertJsonPath('duplicate_rule', null)
            ->assertJsonPath('covered_count', 1)
            ->assertJsonPath('missed_count', 1)
            ->assertJsonPath('conflict_count', 1)
            ->assertJsonPath('unassigned_count', 1);

        $this->assertSame([$missed->id], collect($response->json('missed'))->pluck('id')->all());
        $this->assertSame([$conflict->id], collect($response->json('conflicts'))->pluck('id')->all());
        $this->assertSame('Other Coffee', $response->json('conflicts.0.merchant_name'));
        $this->assertSame([$unassigned->id], collect($response->json('unassigned'))->pluck('id')->all());
    }

    public function test_extracted_name_rule_uses_institution_extraction(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Taco Bell',
            'normalized_name' => 'taco bell',
            'supports_order_import' => false,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-01',
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
            'amount' => -8.50,
        ]);
        $missed = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-02',
            'description' => 'CHIPOTLE 4412',
            'normalized_description' => 'chipotle 4412',
            'amount' => -12.00,
        ]);

        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'TB Franchise',
            'normalized_name' => 'tb franchise',
            'supports_order_import' => false,
        ]);
        $conflict = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $other->id,
            'posted_at' => '2026-08-03',
            'description' => 'TACO BELL 021999',
            'normalized_description' => 'taco bell 021999',
            'amount' => -7.25,
        ]);

        $unassigned = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'posted_at' => '2026-08-04',
            'description' => 'TACO BELL 021888',
            'normalized_description' => 'taco bell 021888',
            'amount' => -9.10,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('merchants.rules.check', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
                'pattern' => 'taco bell',
            ])
            ->assertOk()
            ->assertJsonPath('covered_count', 1)
            ->assertJsonPath('missed_count', 1)
            ->assertJsonPath('conflict_count', 1)
            ->assertJsonPath('unassigned_count', 1);

        $this->assertSame([$missed->id], collect($response->json('missed'))->pluck('id')->all());
        $this->assertSame([$conflict->id], collect($response->json('conflicts'))->pluck('id')->all());
        $this->assertSame([$unassigned->id], collect($response->json('unassigned'))->pluck('id')->all());
    }

    public function test_check_does_not_create_a_rule_or_reassign_transactions(): void
    {
        [$user, $merchant, $account] = $this->merchantWithActivity();

        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Other Coffee',
            'normalized_name' => 'other coffee',
            'supports_order_import' => false,
        ]);
        $alreadyAssigned = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $other->id,
            'amount' => -4.50,
            'description' => 'STARBUCKS 123',
            'normalized_description' => 'starbucks 123',
        ]);
        $unassigned = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -9.99,
            'description' => 'SQ *STARBUCKS STORE',
            'normalized_description' => 'sq *starbucks store',
        ]);

        $this->actingAs($user)
            ->postJson(route('merchants.rules.check', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'starbucks',
            ])
            ->assertOk();

        $this->assertDatabaseCount('merchant_matching_rules', 0);
        $this->assertSame($other->id, $alreadyAssigned->fresh()->merchant_id);
        $this->assertNull($unassigned->fresh()->merchant_id);
    }

    public function test_duplicate_pattern_is_reported_not_rejected(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();
        $other = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Star Market',
            'normalized_name' => 'star market',
            'supports_order_import' => false,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $other->id,
            'amount' => -1,
        ]);

        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $other->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'starbucks',
        ]);

        $this->actingAs($user)
            ->postJson(route('merchants.rules.check', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'starbucks',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate_rule.merchant_id', $other->id)
            ->assertJsonPath('duplicate_rule.merchant_name', 'Star Market');

        $this->assertDatabaseCount('merchant_matching_rules', 1);
    }

    public function test_check_returns_404_for_order_import_retailers(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $walmart->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('merchants.rules.check', $walmart), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'walmart',
            ])
            ->assertNotFound();
    }

    public function test_check_returns_404_for_another_users_merchant(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->postJson(route('merchants.rules.check', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'starbucks',
            ])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $merchantAttributes
     * @return array{0: User, 1: Merchant, 2: Account}
     */
    protected function merchantWithActivity(array $merchantAttributes = []): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Starbucks',
            'normalized_name' => 'starbucks',
            'supports_order_import' => false,
            ...$merchantAttributes,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-01',
            'amount' => -5.00,
            'description' => 'STARBUCKS STORE 1',
            'normalized_description' => 'starbucks store 1',
        ]);

        return [$user, $merchant, $account];
    }
}
