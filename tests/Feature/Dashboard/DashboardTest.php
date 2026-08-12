<?php

namespace Tests\Feature\Dashboard;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetCategoryLimit;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
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

    public function test_dashboard_shows_category_and_uncategorized_spend_for_month(): void
    {
        $user = User::factory()->create();
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
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -99.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-07-01',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('view', 'month')
                ->where('month', '2026-08')
                ->where('period.from', '2026-08-01')
                ->where('period.to', '2026-08-31')
                ->where('total_income', 0)
                ->where('total_spend', 75)
                ->where('summary.bills', 25)
                ->where('summary.expenses', 50)
                ->where('summary.budget_allowed', 100)
                ->where('summary.vs_budget_difference', 50)
                ->where('sections.spending.amount', 75)
                ->where('sections.spending.bills.amount', 25)
                ->where('sections.spending.bills.categories.0.name', 'Utilities')
                ->where('sections.spending.bills.categories.0.amount', 25)
                ->where('sections.spending.expenses.amount', 50)
                ->where('sections.spending.expenses.categories.0.name', 'Dining')
                ->where('sections.spending.expenses.categories.0.amount', 40)
                ->where('sections.spending.expenses.categories.0.budget_allowed', 100)
                ->where('sections.spending.expenses.categories.0.vs_budget_difference', 60)
                ->where('sections.spending.expenses.uncategorized.amount', 10)
                ->where('sections.income.categories', []));
    }

    public function test_dashboard_ytm_scales_budgets_and_scopes_actuals(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
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
            'posted_at' => '2026-01-15',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -800.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-02-01',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -50.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-01-20',
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
            'posted_at' => '2025-12-20',
        ]);

        // leftover = 5000 - 800 = 4200
        // expenses = 90, budget allowed = 800, vs budget = 710

        $this->actingAs($user)
            ->get('/?view=ytm')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('view', 'ytm')
                ->where('month', '2026-08')
                ->where('period.from', '2026-01-01')
                ->where('period.to', '2026-08-31')
                ->where('months_elapsed', 8)
                ->where('summary.income', 5000)
                ->where('summary.bills', 800)
                ->where('summary.leftover_income', 4200)
                ->where('summary.expenses', 90)
                ->where('summary.budget_allowed', 800)
                ->where('summary.vs_budget_difference', 710)
                ->where('summary.vs_leftover_difference', 4110)
                ->where('sections.spending.expenses.categories.0.budget_allowed', 800)
                ->where('sections.spending.expenses.categories.0.amount', 90)
                ->where('sections.spending.expenses.categories.0.vs_budget_difference', 710)
                ->where('sections.spending.expenses.categories.0.vs_leftover_difference', 4110));
    }

    public function test_dashboard_hides_zero_spend_expense_categories_even_with_budget(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        Category::factory()->for($user)->expense()->create(['name' => 'Unused']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
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
            'posted_at' => '2026-08-05',
        ]);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-08')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('sections.spending.expenses.categories', 1)
                ->where('sections.spending.expenses.categories.0.name', 'Dining'));
    }

    public function test_order_component_totals_merge_into_dashboard_category_spend(): void
    {
        $user = User::factory()->create();
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

        $query = app(CategorySpendQuery::class);
        $this->assertSame(
            [$groceries->id => 32.5],
            $query->orderComponentCategoryTotalsForUser(
                $user->id,
                Carbon::parse('2026-08-01'),
                Carbon::parse('2026-09-01'),
            ),
        );
        $this->assertSame(
            2.5,
            $query->orderComponentUncategorizedSpendForUser(
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
                ->where('sections.spending.expenses.categories.0.name', 'Groceries')
                ->where('sections.spending.expenses.categories.0.amount', 42.5)
                ->where('sections.spending.expenses.uncategorized.amount', 2.5)
                ->where('total_spend', 45));
    }

    public function test_dashboard_shows_income_categories_and_omits_zero_amounts(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create([
            'name' => 'Salary',
            'color' => '#228B22',
        ]);
        Category::factory()->for($user)->income()->create([
            'name' => 'Unused Income',
            'color' => '#111111',
        ]);
        Category::factory()->for($user)->expense()->create([
            'name' => 'Unused Expense',
            'color' => '#222222',
        ]);

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
                ->where('total_spend', 0)
                ->where('summary.leftover_income', 2500)
                ->where('sections.income.amount', 2500)
                ->where('sections.income.uncategorized', null)
                ->where('sections.income.categories', fn ($categories) => count($categories) === 1
                    && $categories[0]['name'] === 'Salary'
                    && $categories[0]['amount'] === 2500
                    && $categories[0]['percent'] === 100
                    && $categories[0]['color'] === '#228B22')
                ->where('sections.spending.bills.categories', [])
                ->where('sections.spending.expenses.categories', [])
                ->where('sections.spending.expenses.uncategorized', null));
    }

    public function test_dashboard_shows_uncategorized_income_from_over_reimbursement(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create([
            'name' => 'Salary',
            'color' => '#228B22',
        ]);

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
                ->where('sections.income.amount', 500)
                ->where('sections.income.categories.0.name', 'Salary')
                ->where('sections.income.categories.0.amount', 450)
                ->where('sections.income.categories.0.percent', 90)
                ->where('sections.income.uncategorized.amount', 50)
                ->where('sections.income.uncategorized.percent', 10)
                ->where('sections.spending.expenses.uncategorized', null));
    }
}
