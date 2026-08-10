<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\ApplyIncomeClassificationRun;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CategorizationRun;
use App\Models\ImportBatch;
use App\Models\TransactionClassificationRule;
use App\Models\User;
use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncomeClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_heuristic_suggests_income_without_hiding(): void
    {
        [$user, $account, $batch] = $this->setupAccount();

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 2200.00,
            'posted_at' => '2026-08-01',
            'description' => 'ACME CORP PAYROLL',
            'normalized_description' => 'acme corp payroll',
            'status' => 'unmatched',
        ]);

        $result = app(IncomeClassificationService::class)->classifyForUser($user->id);

        $this->assertSame(0, $result['learned']);
        $this->assertSame(1, $result['suggested']);

        $transaction->refresh();

        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $transaction->classification);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC, $transaction->classification_source);
        $this->assertSame('unmatched', $transaction->status);
    }

    public function test_confirm_creates_user_rule_and_hides_transaction(): void
    {
        [$user, $account, $batch] = $this->setupAccount();
        $service = app(IncomeClassificationService::class);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 1800.00,
            'posted_at' => '2026-08-01',
            'description' => 'ACME CORP PAYROLL',
            'normalized_description' => 'acme corp payroll',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
        ]);

        $service->confirmIncome(
            $transaction,
            TransactionClassificationRule::MATCH_DESCRIPTION,
        );
        $transaction->refresh();

        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_MANUAL, $transaction->classification_source);
        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'normalized_pattern' => 'acme corp payroll',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'amount' => null,
            'is_active' => true,
        ]);
    }

    public function test_confirm_with_exact_amount_does_not_match_different_amount(): void
    {
        [$user, $account, $batch] = $this->setupAccount();
        $service = app(IncomeClassificationService::class);

        $fixed = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'posted_at' => '2026-08-01',
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $service->confirmIncome(
            $fixed,
            TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
        );

        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'normalized_pattern' => 'venmo cashout',
            'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'is_active' => true,
        ]);

        $sameAmount = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'posted_at' => '2026-08-15',
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $differentAmount = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 75.00,
            'posted_at' => '2026-08-16',
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $result = $service->classifyForUser($user->id);

        $this->assertSame(1, $result['learned']);
        $this->assertSame(0, $result['suggested']);

        $sameAmount->refresh();
        $differentAmount->refresh();

        $this->assertSame('ignored', $sameAmount->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $sameAmount->classification_source);
        $this->assertNull($differentAmount->classification);
        $this->assertSame('unmatched', $differentAmount->status);
    }

    public function test_confirm_once_does_not_create_rule(): void
    {
        [$user, $account, $batch] = $this->setupAccount();
        $service = app(IncomeClassificationService::class);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 120.00,
            'posted_at' => '2026-08-01',
            'description' => 'ONE TIME BONUS',
            'normalized_description' => 'one time bonus',
            'status' => 'unmatched',
        ]);

        $service->confirmIncome($transaction, TransactionClassificationRule::MATCH_ONCE);

        $this->assertSame('ignored', $transaction->fresh()->status);
        $this->assertDatabaseCount('transaction_classification_rules', 0);
    }

    public function test_learned_rule_auto_classifies_and_hides_future_credits(): void
    {
        [$user, $account, $batch] = $this->setupAccount();

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'acme corp payroll',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'amount' => null,
            'is_active' => true,
            'metadata' => [],
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 1800.00,
            'posted_at' => '2026-08-15',
            'description' => 'ACME CORP PAYROLL',
            'normalized_description' => 'acme corp payroll',
            'status' => 'unmatched',
        ]);

        $result = app(IncomeClassificationService::class)->classifyForUser($user->id);

        $this->assertSame(1, $result['learned']);
        $this->assertSame(0, $result['suggested']);

        $transaction->refresh();

        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $transaction->classification_source);
    }

    public function test_reject_clears_suggestion_and_suppresses_future_heuristic(): void
    {
        [$user, $account, $batch] = $this->setupAccount();
        $service = app(IncomeClassificationService::class);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 50.00,
            'posted_at' => '2026-08-01',
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
        ]);

        $service->rejectIncome($transaction);
        $transaction->refresh();

        $this->assertNull($transaction->classification);
        $this->assertSame('unmatched', $transaction->status);
        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'normalized_pattern' => 'interest payment',
            'origin' => TransactionClassificationRule::ORIGIN_USER_REJECTED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'is_active' => true,
        ]);

        $later = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 12.00,
            'posted_at' => '2026-08-20',
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
            'status' => 'unmatched',
        ]);

        $result = $service->classifyForUser($user->id);

        $this->assertSame(['learned' => 0, 'suggested' => 0], $result);
        $later->refresh();
        $this->assertNull($later->classification);
    }

    public function test_confirm_income_endpoint_dispatches_apply_run_for_persistable_modes(): void
    {
        Queue::fake();

        [$user, $account, $batch] = $this->setupAccount();

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.confirm-income', $transaction), [
                'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            ])
            ->assertRedirect(route('reconciliation.index'));

        $this->assertSame('ignored', $transaction->fresh()->status);

        $run = CategorizationRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $run->metadata['classification']);
        Queue::assertPushed(
            ApplyIncomeClassificationRun::class,
            fn (ApplyIncomeClassificationRun $job) => $job->categorizationRunId === $run->id,
        );
    }

    public function test_confirm_income_once_does_not_dispatch_apply_run(): void
    {
        Queue::fake();

        [$user, $account, $batch] = $this->setupAccount();

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 40.00,
            'description' => 'GIFT',
            'normalized_description' => 'gift',
            'status' => 'unmatched',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.confirm-income', $transaction), [
                'match_mode' => TransactionClassificationRule::MATCH_ONCE,
            ])
            ->assertRedirect(route('reconciliation.index'));

        $this->assertDatabaseCount('transaction_classification_rules', 0);
        $this->assertDatabaseCount('categorization_runs', 0);
        Queue::assertNotPushed(ApplyIncomeClassificationRun::class);
    }

    public function test_apply_run_classifies_other_matching_credits(): void
    {
        [$user, $account, $batch] = $this->setupAccount();

        $source = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'ignored',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_MANUAL,
        ]);

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'venmo cashout',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
            'is_active' => true,
            'metadata' => [],
        ]);

        $other = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $run = CategorizationRun::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'metadata' => [
                'source_transaction_id' => $source->id,
                'classification' => BankTransaction::CLASSIFICATION_INCOME,
                'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            ],
        ]);

        (new ApplyIncomeClassificationRun($run->id))->handle(app(IncomeClassificationService::class));

        $run->refresh();
        $other->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['applied']);
        $this->assertSame('ignored', $other->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_LEARNED, $other->classification_source);
    }

    /**
     * @return array{0: User, 1: Account, 2: ImportBatch}
     */
    protected function setupAccount(): array
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);

        return [$user, $account, $batch];
    }
}
