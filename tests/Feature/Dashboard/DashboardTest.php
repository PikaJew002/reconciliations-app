<?php

namespace Tests\Feature\Dashboard;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\User;
use App\Services\Reporting\CategorySpendQuery;
use App\Services\Reconciliation\ReimbursementGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_home_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_dashboard_shows_category_and_uncategorized_spend(): void
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

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -25.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('total_income', 0)
                ->where('total_spend', 75)
                ->where('sections.spending.amount', 75)
                ->where('sections.spending.bills.amount', 25)
                ->where('sections.spending.bills.categories', fn ($categories) => count($categories) === 1
                    && $categories[0]['name'] === 'Utilities'
                    && $categories[0]['amount'] === 25
                    && $categories[0]['percent'] === 100
                    && $categories[0]['color'] === '#336699')
                ->where('sections.spending.expenses.amount', 40)
                ->where('sections.spending.expenses.categories', fn ($categories) => count($categories) === 1
                    && $categories[0]['name'] === 'Dining'
                    && $categories[0]['amount'] === 40
                    && $categories[0]['percent'] === 100
                    && $categories[0]['color'] === '#FF6600')
                ->where('sections.spending.uncategorized.amount', 10)
                ->where('sections.spending.uncategorized.percent', 13.3)
                ->where('sections.income.categories', [])
                ->where('sections.income.uncategorized', null));
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
            'posted_at' => '2026-02-01',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-03-15',
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
        $this->assertSame([$groceries->id => 32.5], $query->orderComponentCategoryTotalsForUser($user->id));
        $this->assertSame(2.5, $query->orderComponentUncategorizedSpendForUser($user->id));
        $this->assertSame([
            'from' => '2026-02-01',
            'to' => '2026-03-15',
            'bank_from' => '2026-02-01',
            'bank_to' => '2026-02-01',
            'orders_from' => '2026-03-15',
            'orders_to' => '2026-03-15',
        ], $query->spendCoverageForUser($user->id));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('sections.spending.expenses.categories.0.name', 'Groceries')
                ->where('sections.spending.expenses.categories.0.amount', 42.5)
                ->where('sections.spending.uncategorized.amount', 2.5)
                ->where('total_spend', 45)
                ->where('coverage.from', '2026-02-01')
                ->where('coverage.to', '2026-03-15')
                ->where('coverage.bank_from', '2026-02-01')
                ->where('coverage.orders_to', '2026-03-15'));
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
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('total_income', 2500)
                ->where('total_spend', 0)
                ->where('sections.income.amount', 2500)
                ->where('sections.income.uncategorized', null)
                ->where('sections.income.categories', fn ($categories) => count($categories) === 1
                    && $categories[0]['name'] === 'Salary'
                    && $categories[0]['amount'] === 2500
                    && $categories[0]['percent'] === 100
                    && $categories[0]['color'] === '#228B22')
                ->where('sections.spending.bills.categories', [])
                ->where('sections.spending.expenses.categories', [])
                ->where('sections.spending.uncategorized', null));
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
        ]);

        $expense = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -100.0,
            'status' => 'unmatched',
        ]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 150.0,
            'status' => 'unmatched',
        ]);

        $service = app(ReimbursementGroupService::class);
        $group = $service->create($user->id, [$expense->id, $credit->id]);
        $service->close($group, null, BankTransaction::CLASSIFICATION_INCOME);

        $this->assertSame(50.0, app(CategorySpendQuery::class)->uncategorizedIncomeForUser($user->id));

        $this->actingAs($user)
            ->get(route('dashboard'))
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
                ->where('sections.spending.uncategorized', null));
    }
}
