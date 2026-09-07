<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Models\VacationWindow;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use App\Services\Reconciliation\TransactionCategorizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VacationWindowCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_learned_debit_rules_skip_vacation_posted_at_and_still_apply_outside(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'merchant_id' => $merchant->id,
            'normalized_pattern' => null,
            'amount' => null,
            'is_active' => true,
        ]);

        $inside = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -18.5,
            'posted_at' => '2026-08-05',
            'description' => 'CHIPOTLE VACATION',
        ]);
        $outside = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -12.0,
            'posted_at' => '2026-08-15',
            'description' => 'CHIPOTLE HOME',
        ]);

        $result = app(TransactionCategorizationService::class)->categorizeForUser($user->id);

        $this->assertSame(1, $result['applied']);
        $this->assertNull($inside->fresh()->classification);
        $this->assertSame('unmatched', $inside->fresh()->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $outside->fresh()->classification);
        $this->assertSame($category->id, $outside->fresh()->category_id);
    }

    public function test_income_rules_still_apply_inside_a_vacation_window(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
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

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 12.5,
            'posted_at' => '2026-08-05',
            'description' => 'INTEREST PAYMENT',
            'normalized_description' => 'interest payment',
            'status' => 'unmatched',
            'classification' => null,
        ]);

        $result = app(TransactionCategorizationService::class)->categorizeForUser($user->id);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $credit->fresh()->classification);
        $this->assertSame($category->id, $credit->fresh()->category_id);
    }

    public function test_planned_bills_still_match_inside_a_vacation_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));

        try {
            $this->assertPlannedBillMatchesInsideVacationWindow();
        } finally {
            Carbon::setTestNow();
        }
    }

    protected function assertPlannedBillMatchesInsideVacationWindow(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-20',
        ]);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        $template = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'duke energy',
            'amount' => 140,
            'expected_day' => 15,
            'expected_amount' => 140,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => null,
            'category_id' => null,
            'posted_at' => '2026-03-16',
            'description' => 'DUKE ENERGY 8821',
            'normalized_description' => 'duke energy 8821',
        ]);

        $result = app(PlannedOccurrenceMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['matched']);
        $this->assertDatabaseHas('planned_occurrences', [
            'template_id' => $template->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $transaction->id,
        ]);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $transaction->fresh()->classification);
        $this->assertSame($utilities->id, $transaction->fresh()->category_id);
    }

    public function test_vacation_window_debit_rejects_persistent_match_modes(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create();
        $transaction = $this->debitTransaction($user, [
            'merchant_id' => $merchant->id,
            'amount' => -18.5,
            'posted_at' => '2026-08-05',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            ])
            ->assertStatus(422);

        $this->assertNull($transaction->fresh()->classification);
        $this->assertDatabaseCount('transaction_categorization_rules', 0);
    }

    public function test_vacation_window_debit_can_be_categorized_as_a_one_off(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);
        $transaction = $this->debitTransaction($user, [
            'amount' => -40,
            'posted_at' => '2026-08-05',
            'description' => 'CHIPOTLE',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.transactions.categorize', $transaction), [
                'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                'category_id' => $category->id,
                'match_mode' => TransactionCategorizationRule::MATCH_ONCE,
            ])
            ->assertRedirect(route('reconciliation.unmatched-transactions'));

        $this->assertSame($category->id, $transaction->fresh()->category_id);
        $this->assertDatabaseCount('transaction_categorization_rules', 0);
    }

    public function test_unmatched_page_flags_vacation_window_transactions(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'name' => 'Hawaii',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $inside = $this->debitTransaction($user, [
            'amount' => -20,
            'posted_at' => '2026-08-05',
            'description' => 'CHIPOTLE VACATION',
        ]);
        $outside = $this->debitTransaction($user, [
            'amount' => -15,
            'posted_at' => '2026-08-15',
            'description' => 'CHIPOTLE HOME',
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.unmatched-transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/UnmatchedTransactions')
                ->has('vacation_windows', 1)
                ->where('vacation_windows.0.name', 'Hawaii')
                ->where('unmatchedTransactions', function ($transactions) use ($inside, $outside) {
                    $byId = collect($transactions)->keyBy('id');

                    return $byId[$inside->id]['in_vacation_window'] === true
                        && $byId[$inside->id]['one_off_categorize_only'] === true
                        && $byId[$outside->id]['in_vacation_window'] === false
                        && $byId[$outside->id]['one_off_categorize_only'] === false;
                }));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function debitTransaction(User $user, array $overrides = []): BankTransaction
    {
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        return BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'status' => 'unmatched',
            'classification' => null,
            ...$overrides,
        ]);
    }
}
