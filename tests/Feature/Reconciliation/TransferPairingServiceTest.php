<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_suggests_same_day_transfer_like_pair_without_identical_memo(): void
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
        $this->assertSame(1, $result['suggested']);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
        ]);
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

    public function test_suggests_exact_pair_without_transfer_description(): void
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
        $this->assertSame(1, $result['suggested']);

        $debit->refresh();
        $credit->refresh();

        $this->assertSame('unmatched', $debit->status);
        $this->assertNull($debit->classification);
        $this->assertDatabaseHas('transaction_transfer_links', [
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
        ]);
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
            'description' => 'MOVE MONEY',
            'normalized_description' => 'move money',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 40.00,
            'posted_at' => '2026-08-02',
            'description' => 'MONEY ARRIVED',
            'normalized_description' => 'money arrived',
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
            'description' => 'MOVE AGAIN',
            'normalized_description' => 'move again',
            'status' => 'unmatched',
        ]);

        $creditB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checkingB->id,
            'amount' => 15.00,
            'posted_at' => '2026-08-04',
            'description' => 'ARRIVED AGAIN',
            'normalized_description' => 'arrived again',
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
