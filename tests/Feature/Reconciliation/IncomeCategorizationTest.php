<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\ApplyCategorizationRun;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CategorizationRun;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Reconciliation\TransactionCategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncomeCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_requires_a_category(): void
    {
        $user = User::factory()->create();
        $transaction = $this->creditTransaction($user, [
            'amount' => 2500.0,
            'description' => 'DIRECT DEP PAYROLL',
            'normalized_description' => 'direct dep payroll',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame('unmatched', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->classification);
    }

    public function test_user_can_categorize_credit_with_income_category(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $transaction = $this->creditTransaction($user, [
            'amount' => 2500.0,
            'description' => 'DIRECT DEP PAYROLL',
            'normalized_description' => 'direct dep payroll',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $transaction->refresh();
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'normalized_pattern' => 'direct dep payroll',
            'amount' => 2500.0,
        ]);
    }

    public function test_credit_card_cashback_can_be_categorized_as_income(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'account_type' => Account::CREDIT_CARD,
        ]);
        $category = Category::factory()->for($user)->income()->create(['name' => 'Cashback']);
        $transaction = $this->creditTransaction($user, [
            'account_id' => $account->id,
            'amount' => 12.34,
            'description' => 'CASHBACK REWARD',
            'normalized_description' => 'cashback reward',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $transaction->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $transaction->classification);
        $this->assertSame($category->id, $transaction->category_id);
        $this->assertSame('ignored', $transaction->status);
    }

    public function test_income_rule_applies_to_similar_credits_including_credit_card(): void
    {
        $user = User::factory()->create();
        $checking = Account::factory()->create(['account_type' => Account::CHECKING]);
        $card = Account::factory()->create(['account_type' => Account::CREDIT_CARD]);
        $category = Category::factory()->for($user)->income()->create(['name' => 'Cashback']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'merchant_id' => null,
            'normalized_pattern' => 'cashback reward',
            'amount' => null,
            'is_active' => true,
        ]);

        $checkingCredit = $this->creditTransaction($user, [
            'account_id' => $checking->id,
            'amount' => 5.0,
            'description' => 'CASHBACK REWARD',
            'normalized_description' => 'cashback reward',
        ]);
        $cardCredit = $this->creditTransaction($user, [
            'account_id' => $card->id,
            'amount' => 8.5,
            'description' => 'CASHBACK REWARD',
            'normalized_description' => 'cashback reward',
        ]);

        $result = app(TransactionCategorizationService::class)->categorizeForUser($user->id);

        $this->assertSame(2, $result['applied']);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $checkingCredit->fresh()->classification);
        $this->assertSame($category->id, $checkingCredit->fresh()->category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $cardCredit->fresh()->classification);
        $this->assertSame($category->id, $cardCredit->fresh()->category_id);
    }

    public function test_rejects_expense_category_on_income_and_income_on_debit(): void
    {
        $user = User::factory()->create();
        $expense = Category::factory()->for($user)->expense()->create();
        $income = Category::factory()->for($user)->income()->create();
        $credit = $this->creditTransaction($user, ['amount' => 100.0]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create(['account_type' => Account::CHECKING]);
        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -40.0,
            'description' => 'GROCERY',
            'normalized_description' => 'grocery',
            'status' => 'unmatched',
            'classification' => null,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $credit), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'category_id' => $expense->id,
                'match_mode' => TransactionCategorizationRule::MATCH_ONCE,
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $debit), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'category_id' => $income->id,
                'match_mode' => TransactionCategorizationRule::MATCH_ONCE,
            ])
            ->assertStatus(422);
    }

    public function test_once_income_does_not_create_rule_or_run(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Other']);
        $transaction = $this->creditTransaction($user, [
            'amount' => 50.0,
            'description' => 'ONE TIME CREDIT',
            'normalized_description' => 'one time credit',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_ONCE,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertDatabaseCount('transaction_categorization_rules', 0);
        $this->assertDatabaseCount('categorization_runs', 0);
        Queue::assertNotPushed(ApplyCategorizationRun::class);
        $this->assertSame('ignored', $transaction->fresh()->status);
        $this->assertSame($category->id, $transaction->fresh()->category_id);
    }

    public function test_apply_run_applies_income_rule_to_similar_credits(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->income()->create(['name' => 'Interest']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => 'interest payment',
            'amount' => 12.5,
            'is_active' => true,
        ]);

        $source = $this->creditTransaction($user, [
            'amount' => 12.5,
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
            'status' => 'ignored',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $category->id,
        ]);
        $similar = $this->creditTransaction($user, [
            'amount' => 12.5,
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
        ]);

        $run = CategorizationRun::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'metadata' => [
                'source_transaction_id' => $source->id,
                'category_id' => $category->id,
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            ],
        ]);

        (new ApplyCategorizationRun($run->id))->handle(app(TransactionCategorizationService::class));

        $similar->refresh();
        $this->assertSame('ignored', $similar->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $similar->classification);
        $this->assertSame($category->id, $similar->category_id);
        $this->assertSame('completed', $run->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function creditTransaction(User $user, array $overrides = []): BankTransaction
    {
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = isset($overrides['account_id'])
            ? null
            : Account::factory()->create(['account_type' => Account::CHECKING]);

        return BankTransaction::factory()->create(array_merge([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account?->id,
            'merchant_id' => null,
            'status' => 'unmatched',
            'classification' => null,
            'category_id' => null,
        ], $overrides));
    }
}
