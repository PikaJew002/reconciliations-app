<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransferPairingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_confirms_only_with_identical_memo_and_same_posted_date(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 LEFTOVER',
            'normalized_description' => 'transfer from x6218 to x1758 leftover',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 LEFTOVER',
            'normalized_description' => 'transfer from x6218 to x1758 leftover',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);

        $debit->refresh();
        $credit->refresh();

        $this->assertSame('ignored', $debit->status);
        $this->assertSame('ignored', $credit->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debit->classification);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $credit->classification);
        $this->assertNotNull($debit->transfer_group_id);
        $this->assertSame($debit->transfer_group_id, $credit->transfer_group_id);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'user_id' => $user->id,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'status' => TransactionTransferLink::STATUS_CONFIRMED,
        ]);
    }

    public function test_skips_same_day_pair_without_identical_memo(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER TO X1758',
            'normalized_description' => 'transfer to x1758',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218',
            'normalized_description' => 'transfer from x6218',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_suggests_identical_memo_on_different_posted_dates(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -80.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 AMAZON',
            'normalized_description' => 'transfer from x6218 to x1758 amazon',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 80.00,
            'posted_at' => '2026-08-02',
            'description' => 'TRANSFER FROM X6218 TO X1758 AMAZON',
            'normalized_description' => 'transfer from x6218 to x1758 amazon',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(1, $result['suggested']);
    }

    public function test_unpair_link_clears_classification_and_allows_repairing(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();
        $service = app(TransferPairingService::class);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -40.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 BAD PAIR',
            'normalized_description' => 'transfer from x6218 to x1758 bad pair',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 40.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 BAD PAIR',
            'normalized_description' => 'transfer from x6218 to x1758 bad pair',
            'status' => 'unmatched',
        ]);

        $service->pairForUser($user->id);
        $link = TransactionTransferLink::query()->firstOrFail();

        $service->unpairLink($link);

        $this->assertDatabaseMissing('transaction_transfer_links', ['id' => $link->id]);
        $this->assertSame('unmatched', $debit->fresh()->status);
        $this->assertNull($debit->fresh()->classification);
        $this->assertNull($credit->fresh()->transfer_group_id);

        $result = $service->pairForUser($user->id);

        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debit->fresh()->classification);
    }

    public function test_skips_amount_and_date_pair_without_identical_memo(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -100.00,
            'posted_at' => '2026-08-01',
            'description' => 'ONLINE PAYMENT',
            'normalized_description' => 'online payment',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 100.00,
            'posted_at' => '2026-08-02',
            'description' => 'ONLINE CREDIT',
            'normalized_description' => 'online credit',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);

        $debit->refresh();
        $credit->refresh();

        $this->assertSame('unmatched', $debit->status);
        $this->assertNull($debit->classification);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_skips_when_multiple_credit_candidates_exist_without_identical_memos(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();
        $checkingC = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
            'last_four' => '9999',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -50.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER OUT',
            'normalized_description' => 'transfer out',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 50.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER IN A',
            'normalized_description' => 'transfer in a',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingC->id,
            'amount' => 50.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER IN B',
            'normalized_description' => 'transfer in b',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_pairs_ambiguous_amount_candidates_using_identical_memos(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();

        $debitA = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -50.00,
            'posted_at' => '2026-07-24',
            'description' => 'TRANSFER FROM X6218 TO X1758 CVNB A JULY 03',
            'normalized_description' => 'transfer from x6218 to x1758 cvnb a july 03',
            'status' => 'unmatched',
        ]);

        $debitB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -50.00,
            'posted_at' => '2026-07-24',
            'description' => 'TRANSFER FROM X6218 TO X1758 CVNB H JUL 03',
            'normalized_description' => 'transfer from x6218 to x1758 cvnb h jul 03',
            'status' => 'unmatched',
        ]);

        $creditB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 50.00,
            'posted_at' => '2026-07-24',
            'description' => 'TRANSFER FROM X6218 TO X1758 CVNB H JUL 03',
            'normalized_description' => 'transfer from x6218 to x1758 cvnb h jul 03',
            'status' => 'unmatched',
        ]);

        $creditA = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 50.00,
            'posted_at' => '2026-07-24',
            'description' => 'TRANSFER FROM X6218 TO X1758 CVNB A JULY 03',
            'normalized_description' => 'transfer from x6218 to x1758 cvnb a july 03',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(2, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);

        $debitA->refresh();
        $debitB->refresh();
        $creditA->refresh();
        $creditB->refresh();

        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debitA->classification);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debitB->classification);
        $this->assertSame($debitA->transfer_group_id, $creditA->transfer_group_id);
        $this->assertSame($debitB->transfer_group_id, $creditB->transfer_group_id);
        $this->assertNotSame($debitA->transfer_group_id, $debitB->transfer_group_id);
    }

    public function test_excludes_credit_card_accounts(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $checking = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $creditCard = Account::factory()->create([
            'account_type' => Account::CREDIT_CARD,
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -75.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER TO CARD',
            'normalized_description' => 'transfer to card',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 75.00,
            'posted_at' => '2026-08-01',
            'description' => 'PAYMENT RECEIVED',
            'normalized_description' => 'payment received',
            'status' => 'unmatched',
        ]);

        $result = app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(['confirmed' => 0, 'suggested' => 0], $result);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_confirm_and_reject_suggested_links(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();
        $service = app(TransferPairingService::class);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -40.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 MOVE MONEY',
            'normalized_description' => 'transfer from x6218 to x1758 move money',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 40.00,
            'posted_at' => '2026-08-02',
            'description' => 'TRANSFER FROM X6218 TO X1758 MOVE MONEY',
            'normalized_description' => 'transfer from x6218 to x1758 move money',
            'status' => 'unmatched',
        ]);

        $service->pairForUser($user->id);

        $link = TransactionTransferLink::query()->firstOrFail();
        $service->confirmLink($link);

        $debit->refresh();
        $credit->refresh();
        $link->refresh();

        $this->assertSame(TransactionTransferLink::STATUS_CONFIRMED, $link->status);
        $this->assertSame('ignored', $debit->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $credit->classification);

        $debitB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -15.00,
            'posted_at' => '2026-08-03',
            'description' => 'TRANSFER FROM X6218 TO X1758 MOVE AGAIN',
            'normalized_description' => 'transfer from x6218 to x1758 move again',
            'status' => 'unmatched',
        ]);

        $creditB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 15.00,
            'posted_at' => '2026-08-04',
            'description' => 'TRANSFER FROM X6218 TO X1758 MOVE AGAIN',
            'normalized_description' => 'transfer from x6218 to x1758 move again',
            'status' => 'unmatched',
        ]);

        $service->pairForUser($user->id);
        $rejected = TransactionTransferLink::query()
            ->where('debit_transaction_id', $debitB->id)
            ->firstOrFail();

        $service->rejectLink($rejected);
        $rejected->refresh();
        $debitB->refresh();

        $this->assertSame(TransactionTransferLink::STATUS_REJECTED, $rejected->status);
        $this->assertSame('unmatched', $debitB->status);
        $this->assertNull($debitB->classification);
        $this->assertDatabaseMissing('transaction_transfer_links', [
            'debit_transaction_id' => $debitB->id,
            'credit_transaction_id' => $creditB->id,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
        ]);
    }

    public function test_waits_for_identical_memo_counterparts_instead_of_suggesting_amount_twins(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $bills = Account::factory()->create([
            'name' => 'Bills Account',
            'account_type' => Account::CHECKING,
            'last_four' => '1758',
            'is_active' => true,
        ]);
        $spending = Account::factory()->create([
            'name' => 'Spending Account',
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
            'is_active' => true,
        ]);
        $aaron = Account::factory()->create([
            'name' => "Aaron's Account",
            'account_type' => Account::CHECKING,
            'last_four' => '8955',
            'is_active' => true,
        ]);
        $service = app(TransferPairingService::class);

        $revertCredit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $bills->id,
            'amount' => 1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X8955 TO X1758  REVERT',
            'normalized_description' => 'transfer from x8955 to x1758 revert',
            'status' => 'unmatched',
        ]);
        $daycareDebit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $spending->id,
            'amount' => -1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X6218 TO X8955  DAYCARE BORROW BACK',
            'normalized_description' => 'transfer from x6218 to x8955 daycare borrow back',
            'status' => 'unmatched',
        ]);

        $this->assertSame(
            ['confirmed' => 0, 'suggested' => 0],
            $service->pairForUser($user->id),
        );

        $daycareCredit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $aaron->id,
            'amount' => 1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X6218 TO X8955  DAYCARE BORROW BACK',
            'normalized_description' => 'transfer from x6218 to x8955 daycare borrow back',
            'status' => 'unmatched',
        ]);
        $revertDebit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $aaron->id,
            'amount' => -1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X8955 TO X1758  REVERT',
            'normalized_description' => 'transfer from x8955 to x1758 revert',
            'status' => 'unmatched',
        ]);

        $result = $service->pairForUser($user->id);

        $this->assertSame(2, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'debit_transaction_id' => $daycareDebit->id,
            'credit_transaction_id' => $daycareCredit->id,
            'status' => TransactionTransferLink::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'debit_transaction_id' => $revertDebit->id,
            'credit_transaction_id' => $revertCredit->id,
            'status' => TransactionTransferLink::STATUS_CONFIRMED,
        ]);
    }

    public function test_rejected_wrong_pair_does_not_block_identical_memo_counterpart(): void
    {
        [$user, $checkingA, $checkingB, $batch] = $this->setupAccounts();
        $checkingC = Account::factory()->create([
            'account_type' => Account::CHECKING,
            'last_four' => '8955',
            'is_active' => true,
        ]);
        $service = app(TransferPairingService::class);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingA->id,
            'amount' => -1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X6218 TO X8955 DAYCARE BORROW BACK',
            'normalized_description' => 'transfer from x6218 to x8955 daycare borrow back',
            'status' => 'unmatched',
        ]);
        $wrongCredit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X8955 TO X1758 REVERT',
            'normalized_description' => 'transfer from x8955 to x1758 revert',
            'status' => 'unmatched',
        ]);

        $wrongLink = TransactionTransferLink::query()->create([
            'user_id' => $user->id,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $wrongCredit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => 80,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => ['source' => 'auto'],
        ]);

        $correctCredit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingC->id,
            'amount' => 1558.60,
            'posted_at' => '2026-06-17',
            'description' => 'TRANSFER FROM X6218 TO X8955 DAYCARE BORROW BACK',
            'normalized_description' => 'transfer from x6218 to x8955 daycare borrow back',
            'status' => 'unmatched',
        ]);

        $service->rejectLink($wrongLink);

        $this->assertSame(TransactionTransferLink::STATUS_REJECTED, $wrongLink->fresh()->status);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $correctCredit->id,
            'status' => TransactionTransferLink::STATUS_CONFIRMED,
        ]);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debit->fresh()->classification);
        $this->assertSame('unmatched', $wrongCredit->fresh()->status);
    }

    /**
     * @return array{0: User, 1: Account, 2: Account, 3: ImportBatch}
     */
    protected function setupAccounts(): array
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $checkingA = Account::factory()->create([
            'name' => 'Checking A',
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
            'is_active' => true,
        ]);
        $checkingB = Account::factory()->create([
            'name' => 'Checking B',
            'account_type' => Account::CHECKING,
            'last_four' => '1758',
            'is_active' => true,
        ]);

        return [$user, $checkingA, $checkingB, $batch];
    }
}
