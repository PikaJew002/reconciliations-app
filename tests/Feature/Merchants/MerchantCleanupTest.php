<?php

namespace Tests\Feature\Merchants;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MerchantCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_rename_merchant(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();

        $this->actingAs($user)
            ->patch(route('merchants.update', $merchant), [
                'name' => 'Circle K',
            ])
            ->assertRedirect(route('merchants.show', $merchant))
            ->assertSessionHas('success');

        $this->assertSame('Circle K', $merchant->fresh()->name);
        $this->assertSame('circle k', $merchant->fresh()->normalized_name);
    }

    public function test_rename_rejects_duplicate_normalized_name(): void
    {
        [$user, $merchant] = $this->merchantWithActivity([
            'name' => 'Target',
            'normalized_name' => 'target',
        ]);

        Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Circle K',
            'normalized_name' => 'circle k',
            'supports_order_import' => false,
        ]);

        $this->actingAs($user)
            ->from(route('merchants.show', $merchant))
            ->patch(route('merchants.update', $merchant), [
                'name' => 'Circle K',
            ])
            ->assertRedirect(route('merchants.show', $merchant))
            ->assertSessionHasErrors('normalized_name');

        $this->assertSame('target', $merchant->fresh()->normalized_name);
    }

    public function test_user_can_create_contains_rule_and_rematch_unmatched_transactions(): void
    {
        [$user, $merchant, $account] = $this->merchantWithActivity();

        $unmatched = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -9.99,
            'description' => 'SQ *STARBUCKS STORE',
            'normalized_description' => 'sq *starbucks store',
            'status' => 'unmatched',
            'classification' => null,
        ]);

        $this->actingAs($user)
            ->post(route('merchants.rules.store', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'Starbucks',
            ])
            ->assertRedirect(route('merchants.show', $merchant));

        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'starbucks',
            'is_active' => true,
        ]);
        $this->assertSame($merchant->id, $unmatched->fresh()->merchant_id);
    }

    public function test_creating_rule_does_not_steal_other_merchant_transactions(): void
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
            'status' => 'unmatched',
        ]);

        $this->actingAs($user)
            ->post(route('merchants.rules.store', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'starbucks',
            ])
            ->assertRedirect(route('merchants.show', $merchant));

        $this->assertSame($other->id, $alreadyAssigned->fresh()->merchant_id);
    }

    public function test_user_can_toggle_and_delete_rule(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();

        $rule = MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'starbucks',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('merchants.rules.update', [$merchant, $rule]), [
                'is_active' => false,
            ])
            ->assertRedirect(route('merchants.show', $merchant));

        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($user)
            ->delete(route('merchants.rules.destroy', [$merchant, $rule]))
            ->assertRedirect(route('merchants.show', $merchant));

        $this->assertDatabaseMissing('merchant_matching_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_user_can_add_rule_from_transaction_suggestion(): void
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

        $this->actingAs($user)
            ->get(route('merchants.show', $merchant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Merchants/Show')
                ->where('transactions.0.suggested_rule.match_mode', MerchantMatchingRule::MATCH_EXTRACTED_NAME)
                ->where('transactions.0.suggested_rule.pattern', 'taco bell'));

        $this->actingAs($user)
            ->post(route('merchants.rules.store', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
                'pattern' => 'taco bell',
            ])
            ->assertRedirect(route('merchants.show', $merchant));

        $this->assertDatabaseHas('merchant_matching_rules', [
            'merchant_id' => $merchant->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'taco bell',
        ]);
    }

    public function test_duplicate_pattern_is_rejected(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();
        $other = Merchant::factory()->create([
            'user_id' => $user->id,
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
            ->from(route('merchants.show', $merchant))
            ->post(route('merchants.rules.store', $merchant), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'starbucks',
            ])
            ->assertRedirect(route('merchants.show', $merchant))
            ->assertSessionHasErrors('pattern');
    }

    public function test_cleanup_routes_return_404_for_order_import_retailers(): void
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
            ->patch(route('merchants.update', $walmart), ['name' => 'Walmart Inc'])
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('merchants.rules.store', $walmart), [
                'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
                'pattern' => 'walmart',
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
        ]);

        return [$user, $merchant, $account];
    }
}
