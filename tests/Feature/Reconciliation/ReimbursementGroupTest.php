<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;
use App\Models\User;
use App\Services\Reconciliation\IncomeClassificationService;
use App\Services\Reconciliation\ReimbursementGroupService;
use App\Services\Reporting\CategorySpendQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReimbursementGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_childcare_many_expenses_one_credit_closes_with_fee_remainder(): void
    {
        $user = User::factory()->create();
        $fees = Category::factory()->for($user)->expense()->create(['name' => 'Bank Fees']);

        $chargeA = $this->transaction($user, ['amount' => -400.0, 'description' => 'Brightwheel A']);
        $chargeB = $this->transaction($user, ['amount' => -250.0, 'description' => 'Brightwheel B']);
        $chargeC = $this->transaction($user, ['amount' => -100.0, 'description' => 'Brightwheel C']);
        $credit = $this->transaction($user, ['amount' => 747.0, 'description' => 'KYPAYMENTS KY FINANCE CCD']);

        $this->actingAs($user)
            ->post(route('reconciliation.reimbursement-groups.store'), [
                'transaction_ids' => [$chargeA->id, $chargeB->id, $chargeC->id, $credit->id],
                'name' => 'Childcare Aug',
            ])
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('success');

        $group = ReimbursementGroup::query()->firstOrFail();
        $this->assertSame(ReimbursementGroup::STATUS_OPEN, $group->status);
        $this->assertSame(3.0, $group->fresh('legs')->net());

        foreach ([$chargeA, $chargeB, $chargeC, $credit] as $transaction) {
            $transaction->refresh();
            $this->assertSame('ignored', $transaction->status);
        }

        $credit->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_REIMBURSEMENT, $credit->classification);

        $this->actingAs($user)
            ->post(route('reconciliation.reimbursement-groups.close', $group), [
                'remainder_category_id' => $fees->id,
                'remainder_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            ])
            ->assertRedirect(route('reconciliation.needs-review'));

        $group->refresh();
        $this->assertSame(ReimbursementGroup::STATUS_CLOSED, $group->status);
        $this->assertSame($fees->id, $group->remainder_category_id);

        $totals = app(CategorySpendQuery::class)->categoryTotalsForUser($user->id);
        $this->assertSame(3.0, $totals[$fees->id] ?? null);
    }

    public function test_airbnb_one_expense_many_credits_closes_with_vacation_remainder(): void
    {
        $user = User::factory()->create();
        $vacation = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);

        $airbnb = $this->transaction($user, ['amount' => -1200.0, 'description' => 'AIRBNB']);
        $friend1 = $this->transaction($user, ['amount' => 400.0, 'description' => 'VENMO FRIEND1']);
        $friend2 = $this->transaction($user, ['amount' => 350.0, 'description' => 'VENMO FRIEND2']);
        $friend3 = $this->transaction($user, ['amount' => 300.0, 'description' => 'ZELLE FRIEND3']);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$airbnb->id, $friend1->id, $friend2->id, $friend3->id], 'Cabin trip');

        $this->assertSame(150.0, $group->net());

        $service->close($group, $vacation->id, BankTransaction::CLASSIFICATION_EXPENSE);

        $totals = app(CategorySpendQuery::class)->categoryTotalsForUser($user->id);
        $this->assertSame(150.0, $totals[$vacation->id] ?? null);
        $this->assertSame(0.0, app(CategorySpendQuery::class)->incomeTotalForUser($user->id));
    }

    public function test_late_expense_can_be_added_after_reopen(): void
    {
        $user = User::factory()->create();
        $vacation = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);

        $airbnb = $this->transaction($user, ['amount' => -1000.0, 'description' => 'AIRBNB']);
        $friend = $this->transaction($user, ['amount' => 700.0, 'description' => 'VENMO']);
        $cleaning = $this->transaction($user, ['amount' => -80.0, 'description' => 'AIRBNB CLEANING']);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$airbnb->id, $friend->id]);
        $service->close($group, $vacation->id);

        $this->assertSame(300.0, $group->fresh('legs')->net());

        $service->reopen($group->fresh());
        $service->addTransactions($group->fresh(), [$cleaning->id]);
        $group = $group->fresh('legs');

        $this->assertSame(380.0, $group->net());
        $this->assertSame(ReimbursementGroup::STATUS_OPEN, $group->status);

        $service->close($group, $vacation->id);
        $totals = app(CategorySpendQuery::class)->categoryTotalsForUser($user->id);
        $this->assertSame(380.0, $totals[$vacation->id] ?? null);
    }

    public function test_remove_leg_restores_prior_classification_and_status(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $expense = $this->transaction($user, [
            'amount' => -50.0,
            'description' => 'DINNER',
            'status' => 'ignored',
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_MANUAL,
            'category_id' => $dining->id,
        ]);
        $credit = $this->transaction($user, [
            'amount' => 50.0,
            'description' => 'VENMO',
            'status' => 'unmatched',
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_HEURISTIC,
        ]);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$expense->id, $credit->id]);

        $service->removeTransaction($group, $expense->fresh());
        $expense->refresh();

        $this->assertSame('ignored', $expense->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $expense->classification);
        $this->assertSame($dining->id, $expense->category_id);
        $this->assertFalse(
            ReimbursementGroupTransaction::query()
                ->where('bank_transaction_id', $expense->id)
                ->exists(),
        );
    }

    public function test_close_without_remainder_category_rejected_when_net_nonzero(): void
    {
        $user = User::factory()->create();
        $expense = $this->transaction($user, ['amount' => -100.0]);
        $credit = $this->transaction($user, ['amount' => 90.0]);

        $group = app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $this->actingAs($user)
            ->post(route('reconciliation.reimbursement-groups.close', $group), [])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ReimbursementGroup::STATUS_OPEN, $group->fresh()->status);
    }

    public function test_grouped_credit_is_excluded_from_income_classification(): void
    {
        $user = User::factory()->create();
        $expense = $this->transaction($user, ['amount' => -100.0, 'description' => 'Brightwheel']);
        $credit = $this->transaction($user, [
            'amount' => 100.0,
            'description' => 'ACH CREDIT PAYROLL',
            'normalized_description' => 'ach credit payroll',
        ]);

        app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $result = app(IncomeClassificationService::class)->classifyForUser($user->id);

        $this->assertSame(0, $result['learned']);
        $this->assertSame(0, $result['suggested']);
        $credit->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_REIMBURSEMENT, $credit->classification);
    }

    public function test_grouped_debit_is_not_available_for_expense_matching(): void
    {
        $user = User::factory()->create();
        $expense = $this->transaction($user, ['amount' => -40.0]);
        $credit = $this->transaction($user, ['amount' => 40.0]);

        app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $this->assertFalse(
            BankTransaction::query()
                ->availableForExpenseMatching()
                ->whereKey($expense->id)
                ->exists(),
        );
    }

    public function test_open_group_members_excluded_from_category_totals_and_count_as_awaiting(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $expense = $this->transaction($user, [
            'amount' => -80.0,
            'status' => 'ignored',
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
        ]);
        $credit = $this->transaction($user, ['amount' => 50.0]);

        app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $query = app(CategorySpendQuery::class);
        $this->assertSame([], $query->categoryTotalsForUser($user->id));
        $this->assertSame(30.0, $query->awaitingReimbursementBalance($user->id));
    }

    public function test_uncategorized_spend_counts_ungrouped_null_category_and_excludes_grouped(): void
    {
        $user = User::factory()->create();

        $this->transaction($user, [
            'amount' => -45.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
        ]);
        $this->transaction($user, [
            'amount' => -15.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => null,
        ]);

        $groupedExpense = $this->transaction($user, [
            'amount' => -90.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
        ]);
        $credit = $this->transaction($user, ['amount' => 50.0]);

        app(ReimbursementGroupService::class)->create($user->id, [$groupedExpense->id, $credit->id]);

        $this->assertSame(60.0, app(CategorySpendQuery::class)->uncategorizedSpendForUser($user->id));
    }

    public function test_fully_reimbursed_close_skips_remainder_category(): void
    {
        $user = User::factory()->create();
        $expense = $this->transaction($user, ['amount' => -100.0]);
        $credit = $this->transaction($user, ['amount' => 100.0]);

        $group = app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);
        app(ReimbursementGroupService::class)->close($group);

        $group->refresh();
        $this->assertSame(ReimbursementGroup::STATUS_CLOSED, $group->status);
        $this->assertNull($group->remainder_category_id);
        $this->assertSame([], app(CategorySpendQuery::class)->categoryTotalsForUser($user->id));
    }

    public function test_over_reimbursed_close_books_uncategorized_income_surplus(): void
    {
        $user = User::factory()->create();

        $hotel = $this->transaction($user, ['amount' => -200.0, 'description' => 'HOTEL']);
        $meals = $this->transaction($user, ['amount' => -50.0, 'description' => 'MEALS']);
        $credit = $this->transaction($user, ['amount' => 300.0, 'description' => 'WORK REIMBURSEMENT']);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$hotel->id, $meals->id, $credit->id], 'Work trip');

        $this->assertSame(-50.0, $group->net());

        $this->actingAs($user)
            ->post(route('reconciliation.reimbursement-groups.close', $group), [
                'remainder_classification' => BankTransaction::CLASSIFICATION_INCOME,
            ])
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('success');

        $group->refresh();
        $this->assertSame(ReimbursementGroup::STATUS_CLOSED, $group->status);
        $this->assertNull($group->remainder_category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_INCOME, $group->remainder_classification);

        $query = app(CategorySpendQuery::class);
        $this->assertSame([], $query->categoryTotalsForUser($user->id));
        $this->assertSame(50.0, $query->incomeTotalForUser($user->id));
    }

    public function test_over_reimbursed_close_rejects_expense_remainder(): void
    {
        $user = User::factory()->create();
        $fees = Category::factory()->for($user)->expense()->create(['name' => 'Fees']);

        $expense = $this->transaction($user, ['amount' => -100.0]);
        $credit = $this->transaction($user, ['amount' => 150.0]);

        $group = app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $this->actingAs($user)
            ->post(route('reconciliation.reimbursement-groups.close', $group), [
                'remainder_category_id' => $fees->id,
                'remainder_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ReimbursementGroup::STATUS_OPEN, $group->fresh()->status);
    }

    public function test_open_over_reimbursed_group_does_not_reduce_awaiting_balance(): void
    {
        $user = User::factory()->create();

        $expense = $this->transaction($user, ['amount' => -40.0]);
        $credit = $this->transaction($user, ['amount' => 100.0]);

        app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id]);

        $this->assertSame(0.0, app(CategorySpendQuery::class)->awaitingReimbursementBalance($user->id));
    }

    public function test_review_page_includes_open_reimbursement_groups(): void
    {
        $user = User::factory()->create();
        $expense = $this->transaction($user, ['amount' => -25.0, 'description' => 'Brightwheel']);
        $credit = $this->transaction($user, ['amount' => 20.0, 'description' => 'KYPAYMENTS']);

        app(ReimbursementGroupService::class)->create($user->id, [$expense->id, $credit->id], 'Childcare');

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/NeedsReview')
                ->where('summary.open_reimbursement_groups', 1)
                ->has('openReimbursementGroups', 1)
                ->where('openReimbursementGroups.0.net', 5)
                ->has('reimbursementEligibleTransactions'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function transaction(User $user, array $overrides = []): BankTransaction
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
