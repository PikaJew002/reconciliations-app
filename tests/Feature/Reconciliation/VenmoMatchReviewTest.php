<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\VenmoActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VenmoMatchReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_needs_review_includes_suggested_venmo_matches(): void
    {
        $user = User::factory()->create();
        [$activity, $bank] = $this->suggestedMatch($user);

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/NeedsReview')
                ->where('summary.suggested_venmo_matches', 1)
                ->where('summary.needs_review', 1)
                ->has('suggestedVenmoMatches', 1)
                ->where('suggestedVenmoMatches.0.id', $activity->id)
                ->where('suggestedVenmoMatches.0.suggested_transaction.id', $bank->id)
                ->where('suggestedVenmoMatches.0.label', 'Tyler Adams · Car clean'));
    }

    public function test_user_can_confirm_a_suggested_venmo_match(): void
    {
        $user = User::factory()->create();
        [$activity] = $this->suggestedMatch($user);

        $this->actingAs($user)
            ->post(route('reconciliation.venmo.confirm', $activity))
            ->assertRedirect(route('reconciliation.needs-review'));

        $this->assertSame(VenmoActivity::STATUS_CONFIRMED, $activity->fresh()->match_status);
    }

    public function test_user_can_reject_a_suggested_venmo_match(): void
    {
        $user = User::factory()->create();
        [$activity, $bank] = $this->suggestedMatch($user);

        $this->actingAs($user)
            ->post(route('reconciliation.venmo.reject', $activity))
            ->assertRedirect(route('reconciliation.needs-review'));

        $activity->refresh();
        $this->assertSame(VenmoActivity::STATUS_UNMATCHED, $activity->match_status);
        $this->assertNull($activity->bank_transaction_id);
        $this->assertContains($bank->id, $activity->rejectedBankTransactionIds());
    }

    public function test_needs_review_includes_unmatched_bank_funded_merchant_transactions(): void
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
            'amount' => -15.30,
            'posted_at' => '2026-07-27',
            'description' => 'VENMO PURCHASE',
            'normalized_description' => 'venmo purchase',
            'card_last_four' => null,
        ]);
        $activity = VenmoActivity::factory()->bankFundedMerchant('6218', -15.30)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-07-23 16:27:03',
            'to_name' => "McDonald's Corporation",
            'note' => null,
            'match_status' => VenmoActivity::STATUS_UNMATCHED,
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('unmatchedVenmoActivities', 1)
                ->where('unmatchedVenmoActivities.0.id', $activity->id)
                ->where('unmatchedVenmoActivities.0.candidates.0.id', $bank->id)
                ->where('unmatchedVenmoActivities.0.label', "McDonald's Corporation"));
    }

    public function test_user_can_assign_an_unmatched_venmo_activity_to_a_bank_transaction(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '2195',
            'is_active' => true,
        ]);
        $bank = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => -250.00,
            'posted_at' => '2026-06-08',
            'description' => 'VENMO PURCHASE',
            'normalized_description' => 'venmo purchase',
            'card_last_four' => '2195',
        ]);
        $activity = VenmoActivity::factory()->cardPayment('2195', -250.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'occurred_at' => '2026-06-05 19:11:43',
            'note' => 'Extreme',
            'to_name' => 'Tyler Adams',
            'match_status' => VenmoActivity::STATUS_UNMATCHED,
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('unmatchedVenmoActivities', 1)
                ->where('unmatchedVenmoActivities.0.id', $activity->id)
                ->where('unmatchedVenmoActivities.0.candidates.0.id', $bank->id));

        $this->actingAs($user)
            ->post(route('reconciliation.venmo.assign', $activity), [
                'bank_transaction_id' => $bank->id,
            ])
            ->assertRedirect(route('reconciliation.needs-review'));

        $activity->refresh();
        $this->assertSame(VenmoActivity::STATUS_CONFIRMED, $activity->match_status);
        $this->assertSame($bank->id, $activity->bank_transaction_id);
    }

    public function test_unmatched_transactions_include_venmo_summary(): void
    {
        $user = User::factory()->create();
        [$activity, $bank] = $this->suggestedMatch($user);
        $activity->update(['match_status' => VenmoActivity::STATUS_CONFIRMED]);

        $this->actingAs($user)
            ->get(route('reconciliation.unmatched-transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/UnmatchedTransactions')
                ->where('unmatchedTransactions.0.id', $bank->id)
                ->where('unmatchedTransactions.0.venmo_summary', 'Tyler Adams · Car clean'));
    }

    public function test_cannot_confirm_another_users_venmo_activity(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$activity] = $this->suggestedMatch($other);

        $this->actingAs($user)
            ->post(route('reconciliation.venmo.confirm', $activity))
            ->assertForbidden();
    }

    /**
     * @return array{0: VenmoActivity, 1: BankTransaction}
     */
    protected function suggestedMatch(User $user): array
    {
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '2195',
            'is_active' => true,
        ]);
        $bank = BankTransaction::factory()->create([
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
            'bank_transaction_id' => $bank->id,
            'match_status' => VenmoActivity::STATUS_SUGGESTED,
        ]);

        return [$activity, $bank];
    }
}
