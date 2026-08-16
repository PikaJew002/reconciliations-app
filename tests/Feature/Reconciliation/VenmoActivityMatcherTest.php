<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\VenmoActivity;
use App\Services\Reconciliation\VenmoActivityMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenmoActivityMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirms_unique_card_funded_payment_to_matching_bank_debit(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CREDIT_CARD,
            'last_four' => '2195',
            'is_active' => true,
        ]);

        $bank = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-06',
            'description' => 'VENMO PURCHASE 1051937135825',
            'normalized_description' => 'venmo purchase 1051937135825',
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $activity = VenmoActivity::factory()->cardPayment('2195', -250.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-05 19:11:43',
            'note' => 'Extreme',
            'from_name' => 'Aaron Eisenberg',
            'to_name' => 'Tyler Adams',
        ]);

        $result = app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(0, $result['suggested']);
        $activity->refresh();
        $this->assertSame(VenmoActivity::STATUS_CONFIRMED, $activity->match_status);
        $this->assertSame($bank->id, $activity->bank_transaction_id);
        $this->assertSame('Tyler Adams · Extreme', $bank->fresh()->venmoSummary());
    }

    public function test_matches_standard_transfer_to_bank_credit_and_groups_cashout(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
            'is_active' => true,
        ]);

        $bank = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 10.00,
            'posted_at' => '2026-06-23',
            'description' => 'VENMO CASHOUT',
            'normalized_description' => 'venmo cashout',
            'status' => 'unmatched',
        ]);

        $incoming = VenmoActivity::factory()->incomingPayment(10.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-22 01:06:11',
            'note' => 'Excess',
            'from_name' => 'Rod Eisenberg',
            'to_name' => 'Aaron Eisenberg',
        ]);

        $transfer = VenmoActivity::factory()->standardTransfer('6218', -10.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-22 19:53:30',
        ]);

        $result = app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['confirmed']);
        $incoming->refresh();
        $transfer->refresh();
        $this->assertSame($transfer->id, $incoming->cashed_out_by_activity_id);
        $this->assertSame($bank->id, $transfer->bank_transaction_id);
        $this->assertSame(VenmoActivity::STATUS_CONFIRMED, $transfer->match_status);
        $this->assertSame('Rod Eisenberg · Excess', $bank->fresh()->load('venmoActivities.cashedOutPayments')->venmoSummary());
    }

    public function test_marks_uncashed_incoming_payments_wallet_only(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $incoming = VenmoActivity::factory()->incomingPayment(25.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-26 16:12:33',
            'note' => 'Tshirt',
            'from_name' => 'Maureen Rockhill',
        ]);

        $result = app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['wallet_only']);
        $this->assertSame(VenmoActivity::STATUS_WALLET_ONLY, $incoming->fresh()->match_status);
        $this->assertNull($incoming->fresh()->bank_transaction_id);
    }

    public function test_suggests_when_multiple_bank_candidates_exist(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '2195',
            'is_active' => true,
        ]);

        $closer = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -200.00,
            'posted_at' => '2026-06-19',
            'description' => 'VENMO PURCHASE 111',
            'normalized_description' => 'venmo purchase 111',
            'card_last_four' => '2195',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -200.00,
            'posted_at' => '2026-06-20',
            'description' => 'VENMO PURCHASE 222',
            'normalized_description' => 'venmo purchase 222',
            'card_last_four' => '2195',
        ]);

        $activity = VenmoActivity::factory()->cardPayment('2195', -200.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-19 18:05:39',
            'note' => 'Car clean',
            'to_name' => 'Tyler Adams',
        ]);

        $result = app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(1, $result['suggested']);
        $activity->refresh();
        $this->assertSame(VenmoActivity::STATUS_SUGGESTED, $activity->match_status);
        $this->assertSame($closer->id, $activity->bank_transaction_id);
    }

    public function test_does_not_match_wrong_last_four_or_non_venmo_description(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '9999',
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-05',
            'description' => 'VENMO PURCHASE',
            'normalized_description' => 'venmo purchase',
            'card_last_four' => '9999',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-05',
            'description' => 'WALMART',
            'normalized_description' => 'walmart',
            'card_last_four' => '2195',
        ]);

        $activity = VenmoActivity::factory()->cardPayment('2195', -250.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-05 19:11:43',
        ]);

        $result = app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame(0, $result['confirmed']);
        $this->assertSame(VenmoActivity::STATUS_UNMATCHED, $activity->fresh()->match_status);
        $this->assertNull($activity->fresh()->bank_transaction_id);
    }

    public function test_does_not_overwrite_confirmed_matches(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '2195',
            'is_active' => true,
        ]);

        $original = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-05',
            'description' => 'VENMO PURCHASE OLD',
            'normalized_description' => 'venmo purchase old',
            'card_last_four' => '2195',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-05',
            'description' => 'VENMO PURCHASE NEW',
            'normalized_description' => 'venmo purchase new',
            'card_last_four' => '2195',
        ]);

        $activity = VenmoActivity::factory()->cardPayment('2195', -250.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-05 19:11:43',
            'bank_transaction_id' => $original->id,
            'match_status' => VenmoActivity::STATUS_CONFIRMED,
        ]);

        app(VenmoActivityMatcher::class)->matchForUser($user->id);

        $this->assertSame($original->id, $activity->fresh()->bank_transaction_id);
        $this->assertSame(VenmoActivity::STATUS_CONFIRMED, $activity->fresh()->match_status);
    }
}
