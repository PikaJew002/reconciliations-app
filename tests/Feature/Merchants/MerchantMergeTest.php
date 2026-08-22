<?php

namespace Tests\Feature\Merchants;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\PendingSpend;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MerchantMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_merchants_merge(): void
    {
        $this->post(route('merchants.merge'), [
            'merchant_ids' => [1, 2],
        ])->assertRedirect('/login');
    }

    public function test_user_can_merge_merchants_into_the_oldest_record(): void
    {
        [$user, $oldest, $account] = $this->merchantWithActivity([
            'name' => 'Target',
            'normalized_name' => 'target',
            'type' => Merchant::RETAILER,
        ]);

        $middle = $this->additionalMerchant($user, $account, [
            'name' => 'Target Store',
            'normalized_name' => 'target store',
        ]);
        $newest = $this->additionalMerchant($user, $account, [
            'name' => 'TGT',
            'normalized_name' => 'tgt',
        ]);

        $oldestRule = MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $oldest->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target',
        ]);
        $middleRule = MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $middle->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target store',
        ]);
        $newestRule = MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $newest->id,
            'match_mode' => MerchantMatchingRule::MATCH_EXTRACTED_NAME,
            'pattern' => 'tgt',
        ]);

        $middleTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $middle->id,
            'amount' => -12.00,
        ]);
        $newestTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $newest->id,
            'amount' => -8.00,
        ]);

        $unmatched = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -4.50,
            'description' => 'TARGET STORE 88',
            'normalized_description' => 'target store 88',
            'status' => 'unmatched',
            'classification' => null,
        ]);

        $this->actingAs($user)
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$newest->id, $oldest->id, $middle->id],
            ])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('success', 'Merged merchants into Target.');

        $oldest->refresh();
        $this->assertSame('Target', $oldest->name);
        $this->assertSame('target', $oldest->normalized_name);
        $this->assertSame(Merchant::RETAILER, $oldest->type);

        $this->assertSame($oldest->id, $oldestRule->fresh()->merchant_id);
        $this->assertSame($oldest->id, $middleRule->fresh()->merchant_id);
        $this->assertSame($oldest->id, $newestRule->fresh()->merchant_id);
        $this->assertSame($oldest->id, $middleTx->fresh()->merchant_id);
        $this->assertSame($oldest->id, $newestTx->fresh()->merchant_id);
        $this->assertSame($oldest->id, $unmatched->fresh()->merchant_id);

        $this->assertDatabaseMissing('merchants', ['id' => $middle->id]);
        $this->assertDatabaseMissing('merchants', ['id' => $newest->id]);
    }

    public function test_duplicate_matching_rule_is_dropped_instead_of_erroring(): void
    {
        [$user, $oldest, $account] = $this->merchantWithActivity([
            'name' => 'Target',
            'normalized_name' => 'target',
        ]);
        $newer = $this->additionalMerchant($user, $account, [
            'name' => 'TGT',
            'normalized_name' => 'tgt',
        ]);

        MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $oldest->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target',
            'is_active' => true,
        ]);

        Schema::table('merchant_matching_rules', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'match_mode', 'pattern']);
        });

        $duplicate = MerchantMatchingRule::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $newer->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target',
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$oldest->id, $newer->id],
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertDatabaseMissing('merchant_matching_rules', [
            'id' => $duplicate->id,
        ]);
        $this->assertDatabaseHas('merchant_matching_rules', [
            'user_id' => $user->id,
            'merchant_id' => $oldest->id,
            'match_mode' => MerchantMatchingRule::MATCH_CONTAINS,
            'pattern' => 'target',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('merchants', ['id' => $newer->id]);
    }

    public function test_merge_remaps_pending_spends_and_categorization_rules(): void
    {
        [$user, $oldest, $account] = $this->merchantWithActivity([
            'name' => 'Starbucks',
            'normalized_name' => 'starbucks',
        ]);
        $newer = $this->additionalMerchant($user, $account, [
            'name' => 'Sbux',
            'normalized_name' => 'sbux',
        ]);

        $pendingSpend = PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $newer->id,
        ]);

        $category = Category::factory()->for($user)->expense()->create();
        $rule = TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'merchant_id' => $newer->id,
            'normalized_pattern' => null,
            'amount' => null,
        ]);

        $this->actingAs($user)
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$oldest->id, $newer->id],
            ])
            ->assertRedirect(route('orders.index'));

        $this->assertSame($oldest->id, $pendingSpend->fresh()->merchant_id);
        $this->assertSame($oldest->id, $rule->fresh()->merchant_id);
        $this->assertDatabaseMissing('merchants', ['id' => $newer->id]);
    }

    public function test_merge_rejects_fewer_than_two_merchants(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();

        $this->actingAs($user)
            ->from(route('orders.index'))
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$merchant->id],
            ])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHasErrors('merchant_ids');

        $this->assertDatabaseHas('merchants', ['id' => $merchant->id]);
    }

    public function test_merge_returns_404_for_another_users_merchant(): void
    {
        [$user, $merchant] = $this->merchantWithActivity();
        [$otherUser, $otherMerchant] = $this->merchantWithActivity();

        $this->actingAs($user)
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$merchant->id, $otherMerchant->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('merchants', ['id' => $merchant->id]);
        $this->assertDatabaseHas('merchants', ['id' => $otherMerchant->id]);
        $this->assertSame($otherUser->id, $otherMerchant->fresh()->user_id);
    }

    public function test_merge_returns_404_for_order_import_retailers(): void
    {
        [$user, $merchant, $account] = $this->merchantWithActivity();

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
            'amount' => -20.00,
        ]);

        $this->actingAs($user)
            ->post(route('merchants.merge'), [
                'merchant_ids' => [$merchant->id, $walmart->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('merchants', ['id' => $merchant->id]);
        $this->assertDatabaseHas('merchants', ['id' => $walmart->id]);
    }

    /**
     * @param  array<string, mixed>  $merchantAttributes
     * @return array{0: User, 1: Merchant, 2: Account}
     */
    protected function merchantWithActivity(array $merchantAttributes = []): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
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

    /**
     * @param  array<string, mixed>  $merchantAttributes
     */
    protected function additionalMerchant(User $user, Account $account, array $merchantAttributes = []): Merchant
    {
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
            ...$merchantAttributes,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-02',
            'amount' => -6.00,
        ]);

        return $merchant;
    }
}
