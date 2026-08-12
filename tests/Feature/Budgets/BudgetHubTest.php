<?php

namespace Tests\Feature\Budgets;

use App\Models\BudgetCategoryLimit;
use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetHubTest extends TestCase
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

    public function test_guests_are_redirected_from_budgets(): void
    {
        $this->get('/budgets')
            ->assertRedirect('/login');
    }

    public function test_budgets_page_shows_year_plan_setup(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create([
            'name' => 'Dining',
            'color' => '#FF6600',
        ]);
        Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $this->actingAs($user)
            ->get('/budgets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Budgets/Index')
                ->where('year', 2026)
                ->where('total_monthly', 100)
                ->where('total_annual', 1200)
                ->has('categories', 2)
                ->where('categories.0.name', 'Dining')
                ->where('categories.0.monthly_budget', 100)
                ->where('categories.0.annual_budget', 1200)
                ->where('categories.1.name', 'Groceries')
                ->where('categories.1.monthly_budget', null));
    }

    public function test_user_can_upsert_expense_budget_limits(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create();
        $groceries = Category::factory()->for($user)->expense()->create();

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'category_id' => $dining->id,
            'amount' => 50.0,
        ]);

        $this->actingAs($user)
            ->put('/budgets', [
                'limits' => [
                    ['category_id' => $dining->id, 'amount' => 120],
                    ['category_id' => $groceries->id, 'amount' => 200],
                ],
            ])
            ->assertRedirect('/budgets');

        $this->assertDatabaseHas('budget_category_limits', [
            'user_id' => $user->id,
            'category_id' => $dining->id,
            'amount' => 120,
        ]);
        $this->assertDatabaseHas('budget_category_limits', [
            'user_id' => $user->id,
            'category_id' => $groceries->id,
            'amount' => 200,
        ]);
    }

    public function test_clearing_amount_removes_budget_limit(): void
    {
        $user = User::factory()->create();
        $dining = Category::factory()->for($user)->expense()->create();

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'category_id' => $dining->id,
            'amount' => 50.0,
        ]);

        $this->actingAs($user)
            ->from('/budgets')
            ->put('/budgets', [
                'limits' => [
                    ['category_id' => $dining->id, 'amount' => null],
                ],
            ])
            ->assertRedirect('/budgets');

        $this->assertDatabaseMissing('budget_category_limits', [
            'user_id' => $user->id,
            'category_id' => $dining->id,
        ]);
    }

    public function test_bill_and_income_category_limits_are_rejected(): void
    {
        $user = User::factory()->create();
        $bill = Category::factory()->for($user)->bill()->create();
        $income = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)
            ->from('/budgets')
            ->put('/budgets', [
                'limits' => [
                    ['category_id' => $bill->id, 'amount' => 100],
                    ['category_id' => $income->id, 'amount' => 100],
                ],
            ])
            ->assertRedirect('/budgets')
            ->assertSessionHasErrors([
                'limits.0.category_id',
                'limits.1.category_id',
            ]);

        $this->assertDatabaseCount('budget_category_limits', 0);
    }

    public function test_cannot_budget_another_users_expense_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherCategory = Category::factory()->for($other)->expense()->create();

        $this->actingAs($user)
            ->from('/budgets')
            ->put('/budgets', [
                'limits' => [
                    ['category_id' => $otherCategory->id, 'amount' => 100],
                ],
            ])
            ->assertRedirect('/budgets')
            ->assertSessionHasErrors(['limits.0.category_id']);

        $this->assertDatabaseCount('budget_category_limits', 0);
    }
}
