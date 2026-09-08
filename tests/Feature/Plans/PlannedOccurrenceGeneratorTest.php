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

    public function test_sync_keeps_existing_occurrences_outside_the_horizon(): void
    {
        $user = User::factory()->create();
        $template = $this->templateFor($user);

        $pastPlanned = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-01-01')
            ->create();
        $beyondHorizon = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-09-01')
            ->create();
        $resolvedBeyondHorizon = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-10-01')
            ->resolved()
            ->create();

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertDatabaseHas('planned_occurrences', [
            'id' => $pastPlanned->id,
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ]);
        $this->assertDatabaseHas('planned_occurrences', [
            'id' => $beyondHorizon->id,
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ]);
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

    public function test_sync_creates_occurrences_back_to_leftover_tracking_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $user = User::factory()->create([
            'leftover_starts_on' => '2026-07-01',
        ]);
        $template = $this->templateFor($user, '2026-09-07 12:00:00');

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'],
            $this->occurrenceDates($template),
        );
    }

    public function test_backfill_recreates_missing_months_since_the_plan_was_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $user = User::factory()->create();
        $template = $this->templateFor($user, '2026-08-23 03:55:32');

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'],
            $this->occurrenceDates($template),
        );

        PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('scheduled_date', '2026-07-01')
            ->delete();

        $created = app(PlannedOccurrenceGenerator::class)->backfillTemplate($template);

        $this->assertSame(1, $created);
        $this->assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'],
            $this->occurrenceDates($template),
        );
        $this->assertFalse(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('scheduled_date', '2026-06-01')
                ->exists(),
        );
    }

    public function test_backfill_leaves_resolved_and_existing_rows_alone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $user = User::factory()->create();
        $template = $this->templateFor($user, '2026-08-23 03:55:32');
        $july = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-07-01')
            ->resolved()
            ->create();

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertSame(0, app(PlannedOccurrenceGenerator::class)->backfillTemplate($template));
        $this->assertDatabaseHas('planned_occurrences', [
            'id' => $july->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);
        $this->assertSame(1, PlannedOccurrence::query()->where('template_id', $template->id)->whereDate('scheduled_date', '2026-07-01')->count());
    }

    public function test_backfill_command_recreates_missing_months(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $user = User::factory()->create();
        $template = $this->templateFor($user, '2026-08-23 03:55:32');
        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('scheduled_date', '2026-07-01')
            ->delete();

        $this->artisan('plans:generate-occurrences', ['--backfill' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Backfilled 1 missing occurrence(s).');

        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('scheduled_date', '2026-07-01')
                ->exists(),
        );
    }

    public function test_sync_keeps_customized_date_and_amount(): void
    {
        $user = User::factory()->create();
        $template = $this->templateFor($user);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $occurrence = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('scheduled_date', '2026-03-01')
            ->firstOrFail();

        $occurrence->update([
            'expected_date' => '2026-02-28',
            'expected_amount' => 2875.5,
            'date_customized' => true,
            'amount_customized' => true,
        ]);

        $template->update(['expected_amount' => 3100]);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($template->fresh());

        $occurrence->refresh();
        $this->assertSame('2026-02-28', $occurrence->expected_date->toDateString());
        $this->assertSame('2026-03-01', $occurrence->scheduled_date->toDateString());
        $this->assertSame(2875.5, (float) $occurrence->expected_amount);
        $this->assertContains('2026-02-28', $this->occurrenceDates($template));
    }

    public function test_occurrence_generation_is_scheduled_on_the_first_of_the_month(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('plans:generate-occurrences');
    }

    protected function templateFor(User $user, ?string $createdAt = null): PlannedTemplate
    {
        $salary = Category::factory()->for($user)->income()->create();

        return PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'expected_day' => 1,
            ...($createdAt !== null ? ['created_at' => $createdAt] : []),
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
