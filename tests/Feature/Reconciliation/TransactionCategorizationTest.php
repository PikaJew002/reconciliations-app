<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\ApplyCategorizationRun;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CategorizationRun;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_categorize_transaction_as_expense_with_merchant_rule(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $transaction = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -18.5,
            'description' => 'CHIPOTLE 123',
            'normalized_description' => 'chipotle 123',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'))
            ->assertSessionHas('success');

        $transaction->refresh();
        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $transaction->classification);
        $this->assertSame($category->id, $transaction->category_id);

        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'merchant_id' => $merchant->id,
            'is_active' => true,
        ]);

        $run = CategorizationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);
        Queue::assertPushed(ApplyCategorizationRun::class, fn (ApplyCategorizationRun $job) => $job->categorizationRunId === $run->id);
    }

    public function test_once_match_mode_does_not_create_rule_or_apply_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create();
        $transaction = $this->debitTransaction($user, [
            'amount' => -99.0,
            'description' => 'ONE TIME BILL',
            'normalized_description' => 'one time bill',
        ]);

        $this->actingAs($user)->post(route('reconciliation.transactions.categorize', $transaction), [
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $category->id,
            'match_mode' => TransactionCategorizationRule::MATCH_ONCE,
        ])->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertDatabaseCount('transaction_categorization_rules', 0);
        $this->assertDatabaseCount('categorization_runs', 0);
        Queue::assertNotPushed(ApplyCategorizationRun::class);
    }

    public function test_apply_run_categorizes_other_matching_transactions(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $first = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -12.0,
            'description' => 'CHIPOTLE A',
            'normalized_description' => 'chipotle a',
        ]);
        $second = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -19.5,
            'description' => 'CHIPOTLE B',
            'normalized_description' => 'chipotle b',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $first), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $run = CategorizationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['applied'] ?? null);

        $second->refresh();
        $this->assertSame('ignored', $second->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $second->classification);
        $this->assertSame($category->id, $second->category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $second->classification_source);
    }

    public function test_learned_rule_auto_applies_on_service_run(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->bill()->create();

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => 'acme electric',
            'amount' => 120.0,
            'is_active' => true,
        ]);

        $transaction = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -120.0,
            'description' => 'ACME ELECTRIC',
            'normalized_description' => 'acme electric',
        ]);

        $result = app(TransactionCategorizationService::class)->categorizeForUser($user->id);

        $this->assertSame(1, $result['applied']);
        $transaction->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $transaction->classification);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $transaction->classification_source);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame('ignored', $transaction->status);
    }

    public function test_order_import_merchant_cannot_be_txn_categorized(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => true,
        ]);
        $category = Category::factory()->for($user)->expense()->create();
        $transaction = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -40,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            ])
            ->assertStatus(422);

        $transaction->refresh();
        $this->assertNull($transaction->classification);
        $this->assertSame('unmatched', $transaction->status);
    }

    public function test_user_can_categorize_order_component_and_sticky_product(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => null,
        ]);
        $order = Order::factory()->for($user)->create([
            'merchant_id' => $merchant->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.category.update', [$order, $component]), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('reconciliation.needs-review'));

        $component->refresh();
        $product->refresh();
        $this->assertSame($category->id, $component->category_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertTrue($component->is_user_modified);
    }

    public function test_user_can_disable_categorization_rule(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $rule = TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('categorization-rules.update', $rule), [
                'is_active' => false,
            ])
            ->assertRedirect(route('rules.index', ['tab' => 'expenses']));

        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_check_and_amount_bill_rule_matches_other_checks_with_same_amount(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create(['name' => 'Tithe']);

        $first = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -250.0,
            'description' => 'CHECK 1001',
            'normalized_description' => 'check 1001',
        ]);
        $second = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -250.0,
            'description' => 'CHECK 1002',
            'normalized_description' => 'check 1002',
        ]);
        $wrongAmount = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -200.0,
            'description' => 'CHECK 1003',
            'normalized_description' => 'check 1003',
        ]);
        $notACheck = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -250.0,
            'description' => 'CHECKOUT STORE',
            'normalized_description' => 'checkout store',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $first), [
                'classification' => BankTransaction::CLASSIFICATION_BILL,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => TransactionCategorizationRule::CHECK_DESCRIPTION_PREFIX,
            'amount' => 250.0,
            'is_active' => true,
        ]);

        $run = CategorizationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['applied'] ?? null);

        $second->refresh();
        $this->assertSame('ignored', $second->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $second->classification);
        $this->assertSame($category->id, $second->category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $second->classification_source);

        $wrongAmount->refresh();
        $this->assertSame('unmatched', $wrongAmount->status);
        $this->assertNull($wrongAmount->classification);

        $notACheck->refresh();
        $this->assertSame('unmatched', $notACheck->status);
        $this->assertNull($notACheck->classification);
    }

    public function test_check_and_amount_match_mode_rejects_expense_classification(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $transaction = $this->debitTransaction($user, [
            'amount' => -50.0,
            'description' => 'CHECK 55',
            'normalized_description' => 'check 55',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('transaction_categorization_rules', 0);
        $transaction->refresh();
        $this->assertNull($transaction->classification);
    }

    public function test_description_prefix_and_amount_bill_rule_matches_peers(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create(['name' => 'Auto Loan']);

        $first = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -412.33,
            'description' => 'TOYOTA FINANCIAL 9X7K2A',
            'normalized_description' => 'toyota financial 9x7k2a',
        ]);
        $second = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -412.33,
            'description' => 'TOYOTA FINANCIAL A1B2C3',
            'normalized_description' => 'toyota financial a1b2c3',
        ]);
        $wrongAmount = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -400.0,
            'description' => 'TOYOTA FINANCIAL ZZZ999',
            'normalized_description' => 'toyota financial zzz999',
        ]);
        $differentPrefix = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -412.33,
            'description' => 'TOYOTA LEASE 9X7K2A',
            'normalized_description' => 'toyota lease 9x7k2a',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $first), [
                'classification' => BankTransaction::CLASSIFICATION_BILL,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
                'normalized_pattern' => 'toyota financial',
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => 'toyota financial',
            'amount' => 412.33,
            'is_active' => true,
        ]);

        $run = CategorizationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['applied'] ?? null);

        $second->refresh();
        $this->assertSame('ignored', $second->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $second->classification);
        $this->assertSame($category->id, $second->category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $second->classification_source);

        $wrongAmount->refresh();
        $this->assertSame('unmatched', $wrongAmount->status);
        $this->assertNull($wrongAmount->classification);

        $differentPrefix->refresh();
        $this->assertSame('unmatched', $differentPrefix->status);
        $this->assertNull($differentPrefix->classification);
    }

    public function test_description_prefix_and_amount_suggests_prefix_when_omitted(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create(['name' => 'Tithe']);

        $first = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -250.0,
            'description' => 'CM ALLIANCE ACH DRAFT CONF9XK2',
            'normalized_description' => 'cm alliance ach draft conf9xk2',
        ]);
        $second = $this->debitTransaction($user, [
            'merchant_id' => null,
            'amount' => -250.0,
            'description' => 'CM ALLIANCE ACH DRAFT CONFABCD',
            'normalized_description' => 'cm alliance ach draft confabcd',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $first), [
                'classification' => BankTransaction::CLASSIFICATION_BILL,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'cm alliance ach draft',
            'amount' => 250.0,
        ]);

        $second->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $second->classification);
        $this->assertSame($category->id, $second->category_id);
    }

    public function test_description_prefix_and_amount_rejects_expense_classification(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $transaction = $this->debitTransaction($user, [
            'amount' => -50.0,
            'description' => 'TOYOTA FINANCIAL 1234',
            'normalized_description' => 'toyota financial 1234',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
                'normalized_pattern' => 'toyota financial',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('transaction_categorization_rules', 0);
    }

    public function test_description_prefix_and_amount_rejects_short_prefix(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create();
        $transaction = $this->debitTransaction($user, [
            'amount' => -50.0,
            'description' => 'ACH DRAFT 1234',
            'normalized_description' => 'ach draft 1234',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_BILL,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
                'normalized_pattern' => 'ach',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('transaction_categorization_rules', 0);
    }

    public function test_suggest_description_prefix_strips_confirmation_tokens(): void
    {
        $service = app(TransactionCategorizationService::class);

        $this->assertSame(
            'toyota financial',
            $service->suggestDescriptionPrefix('TOYOTA FINANCIAL 9X7K2A'),
        );
        $this->assertSame(
            'cm alliance ach draft',
            $service->suggestDescriptionPrefix('cm alliance ach draft conf9xk2'),
        );
        $this->assertSame(
            'cm alliance ach draft',
            $service->suggestDescriptionPrefix('cm alliance ach draft 12345678'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function debitTransaction(User $user, array $overrides = []): BankTransaction
    {
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        return BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'status' => 'unmatched',
            'classification' => null,
            ...$overrides,
        ]);
    }
}
