<?php

namespace Tests\Feature\Plans;

use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\User;
use App\Services\Plans\PlannedOccurrenceGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannedOccurrenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sync_creates_last_month_through_two_months_ahead_not_the_full_budget_year(): void
    {
        $user = User::factory()->create();
        BudgetYear::factory()->for($user)->current()->starting('2026-01')->create();
        $template = $this->templateFor($user);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertSame(
            ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01'],
            $this->occurrenceDates($template),
        );
    }

    public function test_sync_removes_unresolved_occurrences_beyond_the_horizon(): void
    {
        $user = User::factory()->create();
        $template = $this->templateFor($user);

        $beyondHorizon = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-09-01')
            ->create();
        $resolvedBeyondHorizon = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-10-01')
            ->resolved()
            ->create();

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertDatabaseMissing('planned_occurrences', ['id' => $beyondHorizon->id]);
        $this->assertDatabaseHas('planned_occurrences', [
            'id' => $resolvedBeyondHorizon->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);
        $this->assertFalse(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-06-01')
                ->exists(),
        );
    }

    public function test_monthly_command_creates_the_next_horizon_month(): void
    {
        $user = User::factory()->create();
        $template = $this->templateFor($user);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertFalse(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-06-01')
                ->exists(),
        );

        Carbon::setTestNow(Carbon::parse('2026-04-01 00:05:00'));

        $this->artisan('plans:generate-occurrences')
            ->assertSuccessful()
            ->expectsOutputToContain('Synced 1 active plan(s).');

        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-06-01')
                ->exists(),
        );
        $this->assertFalse(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-07-01')
                ->exists(),
        );
    }

    public function test_command_can_limit_to_a_single_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $template = $this->templateFor($user);
        $otherTemplate = $this->templateFor($other);

        $this->artisan('plans:generate-occurrences', ['--user' => $user->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Synced 1 active plan(s).');

        $this->assertNotEmpty($this->occurrenceDates($template));
        $this->assertSame([], $this->occurrenceDates($otherTemplate));
    }

    public function test_occurrence_generation_is_scheduled_on_the_first_of_the_month(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('plans:generate-occurrences');
    }

    protected function templateFor(User $user): PlannedTemplate
    {
        $salary = Category::factory()->for($user)->income()->create();

        return PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'expected_day' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function occurrenceDates(PlannedTemplate $template): array
    {
        return PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->orderBy('expected_date')
            ->pluck('expected_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();
    }
}
