<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TransactionClassificationRule;
use App\Models\User;
use App\Services\Reconciliation\IncomeClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $service->confirmIncome($transaction);
        $transaction->refresh();

        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_SOURCE_MANUAL, $transaction->classification_source);
        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'normalized_pattern' => 'acme corp payroll',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'is_active' => true,
        ]);
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
