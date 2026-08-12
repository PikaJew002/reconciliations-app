<?php

namespace Tests\Feature\Budgets;

use App\Models\BudgetCategoryLimit;
use App\Models\BudgetYear;
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

    public function test_budgets_page_shows_selected_year_plan(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create([
            'color' => '#FF6600',
        ]);
        $dining = Category::factory()->for($user)->expense()->create([
            'name' => 'Dining',
            'color' => '#FF6600',
        ]);
        Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 100.0,
        ]);

        $this->actingAs($user)
            ->get('/budgets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Budgets/Index')
                ->where('budget_year.id', $year->id)
                ->where('budget_year.label', 'Jul 2026 – Jun 2027')
                ->where('budget_year.color', '#FF6600')
                ->where('budget_year.is_current', true)
                ->where('total_monthly', 100)
                ->where('total_annual', 1200)
                ->has('categories', 2)
                ->where('categories.0.name', 'Dining')
                ->where('categories.0.monthly_budget', 100)
                ->where('categories.0.annual_budget', 1200));
    }

    public function test_user_can_create_budget_year(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/budgets/years', [
                'starts_on' => '2026-07',
                'color' => '#336699',
                'make_current' => true,
            ])
            ->assertRedirect();

        $year = BudgetYear::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($year);
        $this->assertSame('2026-07-01', $year->starts_on->toDateString());
        $this->assertSame('Jul 2026 – Jun 2027', $year->label);
        $this->assertSame('#336699', $year->color);
        $this->assertTrue($year->is_current);
    }

    public function test_overlapping_budget_years_are_rejected(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();

        $this->actingAs($user)
            ->from('/budgets')
            ->post('/budgets/years', [
                'starts_on' => '2026-10',
                'color' => '#112233',
            ])
            ->assertRedirect('/budgets')
            ->assertSessionHasErrors(['starts_on']);
    }

    public function test_user_can_upsert_expense_budget_limits_for_year(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $dining = Category::factory()->for($user)->expense()->create();
        $groceries = Category::factory()->for($user)->expense()->create();

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 50.0,
        ]);

        $this->actingAs($user)
            ->put('/budgets', [
                'budget_year_id' => $year->id,
                'limits' => [
                    ['category_id' => $dining->id, 'amount' => 120],
                    ['category_id' => $groceries->id, 'amount' => 200],
                ],
            ])
            ->assertRedirect('/budgets?budget_year_id='.$year->id);

        $this->assertDatabaseHas('budget_category_limits', [
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 120,
        ]);
        $this->assertDatabaseHas('budget_category_limits', [
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $groceries->id,
            'amount' => 200,
        ]);
    }

    public function test_clearing_amount_removes_budget_limit(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $dining = Category::factory()->for($user)->expense()->create();

        BudgetCategoryLimit::factory()->create([
            'user_id' => $user->id,
            'budget_year_id' => $year->id,
            'category_id' => $dining->id,
            'amount' => 50.0,
        ]);

        $this->actingAs($user)
            ->from('/budgets')
            ->put('/budgets', [
                'budget_year_id' => $year->id,
                'limits' => [
                    ['category_id' => $dining->id, 'amount' => null],
                ],
            ])
            ->assertRedirect('/budgets?budget_year_id='.$year->id);

        $this->assertDatabaseMissing('budget_category_limits', [
            'user_id' => $user->id,
            'category_id' => $dining->id,
        ]);
    }

    public function test_bill_and_income_category_limits_are_rejected(): void
    {
        $user = User::factory()->create();
        $year = BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $bill = Category::factory()->for($user)->bill()->create();
        $income = Category::factory()->for($user)->income()->create();

        $this->actingAs($user)
            ->from('/budgets')
            ->put('/budgets', [
                'budget_year_id' => $year->id,
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

    public function test_set_current_budget_year(): void
    {
        $user = User::factory()->create();
        $first = BudgetYear::factory()->for($user)->current()->starting('2025-07')->create();
        $second = BudgetYear::factory()->for($user)->starting('2026-07')->create([
            'is_current' => false,
        ]);

        $this->actingAs($user)
            ->post("/budgets/years/{$second->id}/current")
            ->assertRedirect('/budgets?budget_year_id='.$second->id);

        $this->assertFalse($first->refresh()->is_current);
        $this->assertTrue($second->refresh()->is_current);
    }
}
