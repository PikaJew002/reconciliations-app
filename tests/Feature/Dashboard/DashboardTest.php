<?php

namespace Tests\Feature\Dashboard;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetCategoryLimit;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Reconciliation\ReimbursementGroupService;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_from_home_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_dashboard_ytm_uses_budget_year_window(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create([
            'color' => '#336699',
        ]);
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 5000.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-07-15',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -800.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-07-20',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -50.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-07-20',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-10',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -99.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-06-20',
        ]);

        // Jul–Aug: months_elapsed=2, budget=200, expenses=90

        $this->actingAs($user)
            ->get('/?view=ytm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('view', 'ytm')
                ->where('budget_year.id', $year->id)
                ->where('budget_year.label', 'Jul 2026 – Jun 2027')
                ->where('period.from', '2026-07-01')
                ->where('period.to', '2026-08-31')
                ->where('months_elapsed', 2)
                ->where('summary.income', 5000)
                ->where('summary.bills', 800)
                ->where('summary.leftover_income', 4200)
                ->where('summary.expenses', 90)
                ->where('summary.budget_allowed', 200)
                ->where('summary.vs_budget_difference', 110)
                ->where('summary.vs_leftover_difference', 4110)
                ->where('sections.spending.expenses.categories.0.amount', 90)
                ->where('sections.spending.expenses.categories.0.budget_allowed', 200)
                ->missing('sections.spending.expenses.categories.0.vs_leftover_difference'));
    }

    public function test_dashboard_completed_plan_ytm_is_full_twelve_months(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2025-07')->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -25.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2025-08-01',
        ]);

        $this->actingAs($user)
            ->get('/?view=ytm&budget_year_id='.$year->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('period.from', '2025-07-01')
                ->where('period.to', '2026-06-30')
                ->where('months_elapsed', 12)
                ->where('summary.budget_allowed', 1200)
                ->where('summary.expenses', 25));
    }

    public function test_month_outside_any_plan_has_actuals_without_budget(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-03-10',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('sections.spending.expenses.categories.0.amount', 40)
                ->where('sections.spending.expenses.categories.0.budget_allowed', null)
                ->where('summary.budget_allowed', 0));
    }

    public function test_month_inside_non_current_plan_uses_that_plans_limits(): void
    {
        $user = User::factory()->create();
        $past = BudgetYear::factory()->for($user)->starting('2025-07')->create([
            'is_current' => false,
            'color' => '#111111',
        ]);
        $current = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create([
            'color' => '#222222',
        ]);
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $past->id,
            'category_id' => $dining->id,
            'amount' => 50.0,
        ]);
        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $current->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -20.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2025-09-10',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2025-09&budget_year_id='.$current->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('budget_year.id', $current->id)
                ->where('sections.spending.expenses.categories.0.amount', 20)
                ->where('sections.spending.expenses.categories.0.budget_allowed', 50)
                ->where('sections.spending.expenses.categories.0.vs_budget_difference', 30));
    }

    public function test_dashboard_shows_category_and_uncategorized_spend_for_month(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $dining = Category::factory()->for($user)->expense()->create([
            'name' => 'Dining',
            'color' => '#FF6600',
        ]);
        $utilities = Category::factory()->for($user)->bill()->create([
            'name' => 'Utilities',
            'color' => '#336699',
        ]);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-10',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -25.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-08-11',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
            'posted_at' => '2026-08-12',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('view', 'month')
                ->where('month', '2026-08')
                ->where('total_spend', 75)
                ->where('summary.expenses', 50)
                ->where('summary.budget_allowed', 100)
                ->where('sections.spending.expenses.categories.0.budget_allowed', 100)
                ->where('sections.spending.expenses.uncategorized.amount', 10));
    }

    public function test_order_component_totals_merge_into_dashboard_category_spend(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $groceries = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create();

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $groceries->id,
            'posted_at' => '2026-08-01',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-15',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 32.5,
            'category_id' => $groceries->id,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'amount' => 2.5,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('sections.spending.expenses.categories.0.amount', 42.5)
                ->where('sections.spending.expenses.uncategorized.amount', 2.5)
                ->where('total_spend', 45));
    }

    public function test_dashboard_shows_income_categories_and_omits_zero_amounts(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create([
            'name' => 'Salary',
            'color' => '#228B22',
        ]);
        Category::factory()->for($user)->income()->create(['name' => 'Unused Income']);
        Category::factory()->for($user)->expense()->create(['name' => 'Unused Expense']);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2500.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-08-01',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('total_income', 2500)
                ->where('summary.leftover_income', 2500)
                ->where('sections.income.categories.0.name', 'Salary')
                ->where('sections.spending.expenses.categories', []));
    }

    public function test_dashboard_shows_uncategorized_income_from_over_reimbursement(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 450.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-08-01',
        ]);

        $expense = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -100.0,
            'status' => 'unmatched',
            'posted_at' => '2026-08-02',
        ]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 150.0,
            'status' => 'unmatched',
            'posted_at' => '2026-08-03',
        ]);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$expense->id, $credit->id]);
        $service->close($group, null, BankTransaction::CLASSIFICATION_INCOME);

        $this->assertSame(
            50.0,
            app(CategorySpendQuery::class)->uncategorizedIncomeForUser(
                $user->id,
                Carbon::parse('2026-08-01'),
                Carbon::parse('2026-09-01'),
            ),
        );

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('total_income', 500)
                ->where('sections.income.uncategorized.amount', 50));
    }

    public function test_month_dashboard_shows_projected_paycheck_plan_cards(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
        ]);
        $rent = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_day' => 1,
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);
        $electric = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'expected_day' => 5,
            'expected_amount' => 140,
            'amount' => 140,
        ]);
        $paycheck->assignedBills()->sync([$rent->id, $electric->id]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('paycheck_plans.paychecks', 1)
                ->where('paycheck_plans.paychecks.0.name', 'Acme paycheck')
                ->where('paycheck_plans.paychecks.0.amount', 3000)
                ->where('paycheck_plans.paychecks.0.bills_amount', 1340)
                ->where('paycheck_plans.paychecks.0.leftover', 1660)
                ->where('paycheck_plans.paychecks.0.bills.0.name', 'Rent')
                ->where('paycheck_plans.paychecks.0.bills.1.name', 'Electric')
                ->where('paycheck_plans.leftover', 1660));
    }

    public function test_month_dashboard_paycheck_cards_use_actuals_when_resolved(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
        ]);
        $rent = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_day' => 1,
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);
        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)->get('/?view=month&month=2026-08');

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $paycheckTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'posted_at' => '2026-08-01',
        ]);
        $rentTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1180.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'posted_at' => '2026-08-01',
        ]);

        PlannedOccurrence::query()
            ->where('template_id', $paycheck->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $paycheckTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);
        PlannedOccurrence::query()
            ->where('template_id', $rent->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $rentTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_plans.paychecks.0.amount', 2987)
                ->where('paycheck_plans.paychecks.0.bills.0.amount', 1180)
                ->where('paycheck_plans.paychecks.0.leftover', 1807)
                ->where('paycheck_plans.leftover', 1807));
    }

    public function test_month_dashboard_pairs_earlier_bill_day_with_next_month_occurrence(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $giving = Category::factory()->for($user)->bill()->create(['name' => 'Giving']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 15,
            'expected_amount' => 3000,
        ]);
        $tithe = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $giving->id,
            'name' => 'Tithe - JCC',
            'expected_day' => 1,
            'expected_amount' => 300,
            'amount' => 300,
        ]);
        $electric = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'expected_day' => 20,
            'expected_amount' => 140,
            'amount' => 140,
        ]);
        $paycheck->assignedBills()->sync([$tithe->id, $electric->id]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-07')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_plans.paychecks.0.expected_date', '2026-07-15')
                ->where('paycheck_plans.paychecks.0.bills.0.name', 'Tithe - JCC')
                ->where('paycheck_plans.paychecks.0.bills.0.expected_date', '2026-08-01')
                ->where('paycheck_plans.paychecks.0.bills.0.covers_next_month', true)
                ->where('paycheck_plans.paychecks.0.bills.1.name', 'Electric')
                ->where('paycheck_plans.paychecks.0.bills.1.expected_date', '2026-07-20')
                ->where('paycheck_plans.paychecks.0.bills.1.covers_next_month', false)
                ->where('paycheck_plans.paychecks.0.bills_amount', 440)
                ->where('paycheck_plans.paychecks.0.leftover', 2560));

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_plans.paychecks.0.expected_date', '2026-08-15')
                ->where('paycheck_plans.paychecks.0.bills.0.expected_date', '2026-09-01')
                ->where('paycheck_plans.paychecks.0.bills.1.expected_date', '2026-08-20'));
    }

    public function test_month_dashboard_uses_next_month_bill_actual_when_resolved(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $giving = Category::factory()->for($user)->bill()->create(['name' => 'Giving']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 15,
            'expected_amount' => 3000,
        ]);
        $tithe = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $giving->id,
            'name' => 'Tithe - JCC',
            'expected_day' => 1,
            'expected_amount' => 300,
            'amount' => 300,
        ]);
        $paycheck->assignedBills()->sync([$tithe->id]);

        $this->actingAs($user)->get('/?view=month&month=2026-07');

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $titheTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -280.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'posted_at' => '2026-08-01',
        ]);

        PlannedOccurrence::query()
            ->where('template_id', $tithe->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $titheTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-07')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_plans.paychecks.0.bills.0.expected_date', '2026-08-01')
                ->where('paycheck_plans.paychecks.0.bills.0.amount', 280)
                ->where('paycheck_plans.paychecks.0.leftover', 2720));
    }

    public function test_ytm_dashboard_omits_paycheck_plan_cards(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'expected_amount' => 3000,
        ]);

        $this->actingAs($user)
            ->get('/?view=ytm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_plans.paychecks', []));
    }
}
