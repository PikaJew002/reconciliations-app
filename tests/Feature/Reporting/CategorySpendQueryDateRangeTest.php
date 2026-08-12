<?php

namespace Tests\Feature\Reporting;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\User;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySpendQueryDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_totals_respect_posted_at_half_open_window(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-07-31',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -25.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-15',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-09-01',
        ]);

        $query = app(CategorySpendQuery::class);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');

        $totals = $query->categoryTotalsForUser($user->id, $from, $to);

        $this->assertSame(25.0, $totals[$dining->id]);
    }

    public function test_uncategorized_expense_and_bill_splits_respect_date_range(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -30.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
            'posted_at' => '2026-08-10',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -20.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => null,
            'posted_at' => '2026-08-12',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -50.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
            'posted_at' => '2026-07-01',
        ]);

        $query = app(CategorySpendQuery::class);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');

        $this->assertSame(30.0, $query->uncategorizedExpenseSpendForUser($user->id, $from, $to));
        $this->assertSame(20.0, $query->uncategorizedBillSpendForUser($user->id, $from, $to));
        $this->assertSame(50.0, $query->uncategorizedSpendForUser($user->id, $from, $to));
    }

    public function test_order_component_totals_respect_ordered_at_range(): void
    {
        $user = User::factory()->create();
        $groceries = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $inRange = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-15',
        ]);
        $outOfRange = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-07-15',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $inRange->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 12.0,
            'category_id' => $groceries->id,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $outOfRange->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 99.0,
            'category_id' => $groceries->id,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $inRange->id,
            'order_item_id' => null,
            'type' => 'tax',
            'amount' => 3.0,
            'category_id' => null,
        ]);

        $query = app(CategorySpendQuery::class);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');

        $this->assertSame(
            [$groceries->id => 12.0],
            $query->orderComponentCategoryTotalsForUser($user->id, $from, $to),
        );
        $this->assertSame(3.0, $query->orderComponentUncategorizedSpendForUser($user->id, $from, $to));
    }

    public function test_income_totals_respect_posted_at_range(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 1000.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-08-01',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 50.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => null,
            'posted_at' => '2026-08-20',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 500.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-09-01',
        ]);

        $query = app(CategorySpendQuery::class);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');

        $this->assertSame([$salary->id => 1000.0], $query->incomeCategoryTotalsForUser($user->id, $from, $to));
        $this->assertSame(50.0, $query->uncategorizedIncomeForUser($user->id, $from, $to));
        $this->assertSame(1050.0, $query->incomeTotalForUser($user->id, $from, $to));
    }
}
