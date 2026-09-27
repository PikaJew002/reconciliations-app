<?php

namespace Tests\Feature\Plans;

use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpensePlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creating_an_expense_plan_generates_monthly_occurrences(): void
    {
        $user = User::factory()->create([
            'leftover_starts_on' => '2026-07-01',
        ]);
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $subscriptions = Category::factory()->for($user)->expense()->create(['name' => 'Subscriptions']);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Gym',
                'category_id' => $subscriptions->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'planet fitness',
                'expected_day' => 10,
                'expected_amount' => 40,
                'occurrences_starts_on' => '2026-09',
                'lookback_days' => 7,
                'lookforward_days' => 3,
            ])
            ->assertRedirect(route('plans.index'));

        $template = PlannedTemplate::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $template->classification);
        $this->assertSame(1, (int) $template->recurrence_months);
        $this->assertSame('2026-09-01', $template->occurrences_starts_on->toDateString());
        $this->assertEquals(40, (float) $template->amount);

        foreach (['2026-09-10', '2026-10-10', '2026-11-10'] as $date) {
            $this->assertTrue(
                PlannedOccurrence::query()
                    ->where('template_id', $template->id)
                    ->where('classification', BankTransaction::CLASSIFICATION_EXPENSE)
                    ->whereDate('expected_date', $date)
                    ->exists(),
                "Missing occurrence for {$date}",
            );
        }

        $this->assertFalse(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-08-10')
                ->exists(),
        );

        $this->actingAs($user)
            ->get(route('plans.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('expense_templates', 1)
                ->where('expense_templates.0.name', 'Gym')
                ->where('expense_templates.0.classification', 'expense')
                ->has('expense_occurrences', 1)
                ->where('expense_occurrences.0.expected_date', '2026-09-10'));
    }
}
