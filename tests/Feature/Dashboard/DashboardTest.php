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
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

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
                ->where('uncategorized_amount', 10)
                ->where('uncategorized_percent', 13.3)
                ->where('total_spend', 75)
                ->where('breakdown.bills.amount', 25)
                ->where('breakdown.bills.percent', 33.3)
                ->where('breakdown.expenses.amount', 40)
                ->where('breakdown.expenses.percent', 53.3)
                ->where('breakdown.uncategorized.amount', 10)
                ->where('breakdown.uncategorized.percent', 13.3)
                ->has('categories', 2)
                ->where('categories.0.name', 'Dining')
                ->where('categories.0.amount', 40)
                ->where('categories.0.percent', 100)
                ->where('categories.1.name', 'Utilities')
                ->where('categories.1.amount', 25)
                ->where('categories.1.percent', 100));
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
                ->where('categories.0.name', 'Groceries')
                ->where('categories.0.amount', 42.5)
                ->where('uncategorized_amount', 2.5)
                ->where('total_spend', 45)
                ->where('coverage.from', '2026-02-01')
                ->where('coverage.to', '2026-03-15')
                ->where('coverage.bank_from', '2026-02-01')
                ->where('coverage.orders_to', '2026-03-15'));
    }
}
