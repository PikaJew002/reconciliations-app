<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Services\Reconciliation\CreditCardPaymentPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditCardPaymentPairingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_confirms_capital_one_payment_within_date_window(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -1017.04,
            'posted_at' => '2026-07-27',
            'description' => 'CAPITAL ONE MOBILE PMT CA037586DC1110E',
            'normalized_description' => 'capital one mobile pmt ca037586dc1110e',
            'status' => 'unmatched',
        ]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 1017.04,
            'posted_at' => '2026-07-24',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

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

        $link = TransactionTransferLink::query()->firstOrFail();
        $this->assertSame('credit_card_payment', $link->metadata['kind'] ?? null);
    }

    public function test_greedy_closest_date_resolves_same_amount_overlap(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        $debitA = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -25.00,
            'posted_at' => '2026-07-20',
            'description' => 'CAPITAL ONE MOBILE PMT CA0A5EB07FF0B3F',
            'normalized_description' => 'capital one mobile pmt ca0a5eb07ff0b3f',
            'status' => 'unmatched',
        ]);

        $debitB = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -25.00,
            'posted_at' => '2026-07-20',
            'description' => 'CAPITAL ONE MOBILE PMT CA020A8654B79F5',
            'normalized_description' => 'capital one mobile pmt ca020a8654b79f5',
            'status' => 'unmatched',
        ]);

        $creditEarly = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 25.00,
            'posted_at' => '2026-07-17',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $creditLate = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 25.00,
            'posted_at' => '2026-07-18',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

        $this->assertSame(2, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);

        $debitA->refresh();
        $debitB->refresh();
        $creditEarly->refresh();
        $creditLate->refresh();

        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debitA->classification);
        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $debitB->classification);
        $this->assertNotNull($debitA->transfer_group_id);
        $this->assertNotNull($debitB->transfer_group_id);
        $this->assertNotSame($debitA->transfer_group_id, $debitB->transfer_group_id);
        $this->assertContains($creditEarly->transfer_group_id, [
            $debitA->transfer_group_id,
            $debitB->transfer_group_id,
        ]);
        $this->assertContains($creditLate->transfer_group_id, [
            $debitA->transfer_group_id,
            $debitB->transfer_group_id,
        ]);
    }

    public function test_skips_cash_back_rewards_and_non_payment_debits(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -35.00,
            'posted_at' => '2026-07-04',
            'description' => 'JOURNEYCOMMUNITY WEBPAYMENT',
            'normalized_description' => 'journeycommunity webpayment',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 35.00,
            'posted_at' => '2026-07-04',
            'description' => 'CREDIT-CASH BACK REWARD',
            'normalized_description' => 'credit-cash back reward',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

        $this->assertSame(['confirmed' => 0, 'suggested' => 0], $result);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_does_not_pair_mastercard_payment_without_credit_card_side(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -50.00,
            'posted_at' => '2026-07-28',
            'description' => 'MASTERCARD PAYMENT 5480323807XXX91',
            'normalized_description' => 'mastercard payment 5480323807xxx91',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 217.00,
            'posted_at' => '2026-06-30',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

        $this->assertSame(['confirmed' => 0, 'suggested' => 0], $result);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_skips_when_outside_date_window(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -172.00,
            'posted_at' => '2026-06-17',
            'description' => 'CAPITAL ONE MOBILE PMT CA045769A73B94C',
            'normalized_description' => 'capital one mobile pmt ca045769a73b94c',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 172.00,
            'posted_at' => '2026-06-12',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

        $this->assertSame(['confirmed' => 0, 'suggested' => 0], $result);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    public function test_skips_equal_gap_ties_instead_of_guessing(): void
    {
        [$user, $checking, $creditCard, $batch] = $this->setupAccounts();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $checking->id,
            'amount' => -40.00,
            'posted_at' => '2026-07-10',
            'description' => 'CAPITAL ONE MOBILE PMT CA0TIE000000001',
            'normalized_description' => 'capital one mobile pmt ca0tie000000001',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 40.00,
            'posted_at' => '2026-07-09',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $creditCard->id,
            'amount' => 40.00,
            'posted_at' => '2026-07-11',
            'description' => 'CAPITAL ONE MOBILE PYMT',
            'normalized_description' => 'capital one mobile pymt',
            'status' => 'unmatched',
        ]);

        $result = app(CreditCardPaymentPairingService::class)->pairForUser($user->id);

        $this->assertSame(['confirmed' => 0, 'suggested' => 0], $result);
        $this->assertDatabaseCount('transaction_transfer_links', 0);
    }

    /**
     * @return array{0: User, 1: Account, 2: Account, 3: ImportBatch}
     */
    protected function setupAccounts(): array
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $checking = Account::factory()->create([
            'name' => 'Checking',
            'account_type' => Account::CHECKING,
            'last_four' => '1758',
            'is_active' => true,
        ]);
        $creditCard = Account::factory()->create([
            'name' => 'Capital One Credit Card',
            'institution_name' => 'Capital One',
            'account_type' => Account::CREDIT_CARD,
            'last_four' => '5394',
            'is_active' => true,
        ]);

        return [$user, $checking, $creditCard, $batch];
    }
}
