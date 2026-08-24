<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\TransactionTransferLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionClassificationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_includes_suggested_transfers_without_suggested_income(): void
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

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/NeedsReview')
                ->where('summary.suggested_transfers', 1)
                ->missing('summary.suggested_income')
                ->has('suggestedTransfers', 1)
                ->where('suggestedTransfers.0.id', $link->id)
                ->missing('suggestedIncome')
                ->where('summary.unmatched_transactions', 0)
            );
    }

    public function test_unmatched_credit_and_debit_expose_can_categorize(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 500.00,
            'posted_at' => '2026-08-01',
            'description' => 'VENMO CASHOUT',
            'status' => 'unmatched',
        ]);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -25.00,
            'posted_at' => '2026-08-01',
            'description' => 'COFFEE',
            'status' => 'unmatched',
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.unmatched-transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/UnmatchedTransactions')
                ->has('unmatchedTransactions', 2)
                ->where('unmatchedTransactions', function ($transactions) use ($credit, $debit) {
                    $byId = collect($transactions)->keyBy('id');

                    return $byId[$credit->id]['can_categorize'] === true
                        && $byId[$credit->id]['account_default_classification'] === 'income'
                        && $byId[$debit->id]['can_categorize'] === true
                        && $byId[$debit->id]['one_off_categorize_only'] === false
                        && ! array_key_exists('can_mark_income', $byId[$credit->id]);
                })
            );
    }

    public function test_unmatched_order_import_debit_can_be_categorized_as_a_one_off(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'supports_order_import' => true,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -42.10,
            'posted_at' => '2026-08-01',
            'description' => 'WALMART.COM',
            'status' => 'unmatched',
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.unmatched-transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/UnmatchedTransactions')
                ->has('unmatchedTransactions', 1)
                ->where('unmatchedTransactions.0.id', $transaction->id)
                ->where('unmatchedTransactions.0.can_categorize', true)
                ->where('unmatchedTransactions.0.one_off_categorize_only', true)
                ->where('unmatchedTransactions.0.supports_order_import', true)
            );
    }

    public function test_zero_amount_transactions_are_ignored_and_hidden_from_unmatched(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'account_type' => Account::SAVINGS,
            'is_active' => true,
        ]);

        $zero = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 0.00,
            'posted_at' => '2026-08-01',
            'description' => 'INTEREST RATE CHANGE',
            'status' => 'unmatched',
            'classification' => null,
        ]);
        $keep = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 1.25,
            'posted_at' => '2026-08-01',
            'description' => 'INTEREST PAYMENT',
            'status' => 'unmatched',
            'classification' => null,
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.unmatched-transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/UnmatchedTransactions')
                ->has('unmatchedTransactions', 1)
                ->where('unmatchedTransactions.0.id', $keep->id)
                ->where('summary.unmatched_transactions', 1)
            );

        $this->assertSame('ignored', $zero->fresh()->status);
        $this->assertNull($zero->fresh()->classification);
        $this->assertSame('unmatched', $keep->fresh()->status);
    }

    public function test_user_can_confirm_and_reject_transfer(): void
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
            ->assertRedirect(route('reconciliation.needs-review'));

        $this->assertSame('ignored', $debit->fresh()->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $credit->fresh()->classification);

        $rejectDebit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -40.00,
            'status' => 'unmatched',
        ]);
        $rejectCredit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 40.00,
            'status' => 'unmatched',
        ]);

        $rejectLink = TransactionTransferLink::query()->create([
            'user_id' => $user->id,
            'debit_transaction_id' => $rejectDebit->id,
            'credit_transaction_id' => $rejectCredit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => 70,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transfers.reject', $rejectLink))
            ->assertRedirect(route('reconciliation.needs-review'));

        $this->assertSame(
            TransactionTransferLink::STATUS_REJECTED,
            $rejectLink->fresh()->status,
        );
    }
}
