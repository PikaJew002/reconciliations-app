<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TransactionTransferLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionClassificationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_includes_suggested_transfers_and_income(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $checkingA = Account::factory()->create([
            'name' => 'Main Checking',
            'account_type' => Account::CHECKING,
            'last_four' => '1111',
            'is_active' => true,
        ]);
        $checkingB = Account::factory()->create([
            'name' => 'Savings',
            'account_type' => Account::SAVINGS,
            'last_four' => '2222',
            'is_active' => true,
        ]);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -100.00,
            'posted_at' => '2026-08-01',
            'description' => 'Move funds',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 100.00,
            'posted_at' => '2026-08-01',
            'description' => 'Funds arrived',
            'status' => 'unmatched',
        ]);

        $link = TransactionTransferLink::query()->create([
            'user_id' => $user->id,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => 80,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => [],
        ]);

        $income = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => 2000.00,
            'posted_at' => '2026-08-02',
            'description' => 'ACME CORP PAYROLL',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
            'classification_confidence' => 70,
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/Index')
                ->where('summary.suggested_transfers', 1)
                ->where('summary.suggested_income', 1)
                ->has('suggestedTransfers', 1)
                ->where('suggestedTransfers.0.id', $link->id)
                ->has('suggestedIncome', 1)
                ->where('suggestedIncome.0.id', $income->id)
                ->where('summary.unmatched_transactions', 0)
            );
    }

    public function test_user_can_confirm_and_reject_transfer_and_income(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $checkingA = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $checkingB = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -60.00,
            'status' => 'unmatched',
        ]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 60.00,
            'status' => 'unmatched',
        ]);

        $link = TransactionTransferLink::query()->create([
            'user_id' => $user->id,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => 80,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transfers.confirm', $link))
            ->assertRedirect(route('reconciliation.index'));

        $this->assertSame('ignored', $debit->fresh()->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $credit->fresh()->classification);

        $income = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => 900.00,
            'description' => 'PAYROLL DEPOSIT',
            'normalized_description' => 'payroll deposit',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.confirm-income', $income))
            ->assertRedirect(route('reconciliation.index'));

        $this->assertSame('ignored', $income->fresh()->status);

        $rejectedIncome = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => 25.00,
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.reject-income', $rejectedIncome))
            ->assertRedirect(route('reconciliation.index'));

        $this->assertNull($rejectedIncome->fresh()->classification);
    }
}
