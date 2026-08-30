<?php

namespace Tests\Feature\Plans;

use App\Jobs\MatchPlannedOccurrences;
use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceMatchRun;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Plans\PaycheckLeftoverService;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlannedOccurrenceOverrideTest extends TestCase
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

    public function test_user_can_adjust_a_paycheck_occurrence_without_changing_the_plan(): void
    {
        Queue::fake();

        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);

        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-03']))
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 2875.5,
                'month' => '2026-03',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-03']));

        $paycheck->refresh();
        $occurrence->refresh();

        $this->assertSame(3000.0, (float) $paycheck->expected_amount);
        $this->assertSame(1, (int) $paycheck->expected_day);
        $this->assertSame('2026-02-28', $occurrence->expected_date->toDateString());
        $this->assertSame('2026-03-01', $occurrence->scheduled_date->toDateString());
        $this->assertSame(2875.5, (float) $occurrence->expected_amount);
        $this->assertTrue($occurrence->date_customized);
        $this->assertTrue($occurrence->amount_customized);

        Queue::assertPushed(MatchPlannedOccurrences::class);
        $this->assertNotNull(
            PlannedOccurrenceMatchRun::query()->where('user_id', $user->id)->first(),
        );
    }

    public function test_user_can_adjust_a_bill_occurrence_amount(): void
    {
        [$user, $bill] = $this->billSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($bill);

        $occurrence = $this->occurrenceFor($bill, '2026-03-15');

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-03-15',
                'expected_amount' => 163.42,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $bill->refresh();
        $occurrence->refresh();

        $this->assertSame(140.0, (float) $bill->expected_amount);
        $this->assertSame(163.42, (float) $occurrence->expected_amount);
        $this->assertFalse($occurrence->date_customized);
        $this->assertTrue($occurrence->amount_customized);
    }

    public function test_adjusted_occurrence_stays_on_the_plans_month_and_survives_sync(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);

        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 2875.5,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $paycheck->update(['expected_amount' => 3100]);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck->fresh());

        $occurrence->refresh();
        $this->assertSame('2026-02-28', $occurrence->expected_date->toDateString());
        $this->assertSame(2875.5, (float) $occurrence->expected_amount);

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where(
                    'paycheck_occurrences',
                    fn ($occurrences) => collect($occurrences)->contains(
                        fn ($item) => $item['id'] === $occurrence->id
                            && $item['expected_date'] === '2026-02-28'
                            && $item['scheduled_date'] === '2026-03-01'
                            && (float) $item['expected_amount'] === 2875.5
                            && $item['date_customized'] === true
                            && $item['amount_customized'] === true,
                    ),
                ));

        $this->actingAs($user)
            ->get('/plans?month=2026-02')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where(
                    'paycheck_occurrences',
                    fn ($occurrences) => collect($occurrences)->every(
                        fn ($item) => $item['id'] !== $occurrence->id,
                    ),
                ));
    }

    public function test_plan_amount_change_still_updates_unadjusted_occurrences(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);

        $march = $this->occurrenceFor($paycheck, '2026-03-01');
        $april = $this->occurrenceFor($paycheck, '2026-04-01');

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $march), [
                'expected_date' => '2026-03-01',
                'expected_amount' => 2800,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $paycheck->update(['expected_amount' => 3100]);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck->fresh());

        $this->assertSame(2800.0, (float) $march->fresh()->expected_amount);
        $this->assertSame(3100.0, (float) $april->fresh()->expected_amount);
    }

    public function test_holiday_paycheck_still_covers_that_month_bills_and_starts_leftover_early(): void
    {
        [$user, $paycheck, $rent] = $this->paycheckWithRent();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($rent);
        $paycheck->assignedBills()->sync([$rent->id]);
        $user->forceFill(['leftover_starts_on' => '2026-03-01'])->save();

        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 2875.5,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where(
                    'paycheck_occurrences',
                    fn ($occurrences) => collect($occurrences)->contains(
                        fn ($item) => $item['id'] === $occurrence->id
                            && $item['expected_date'] === '2026-02-28'
                            && (float) $item['amount'] === 2875.5
                            && (float) $item['leftover'] === 1675.5,
                    ),
                ));

        $windows = app(PaycheckLeftoverService::class)->windows($user->id);
        $holidayWindow = collect($windows)->first(
            fn (array $window) => $window['paycheck']['occurrence_id'] === $occurrence->id,
        );

        $this->assertNotNull($holidayWindow);
        $this->assertSame('2026-02-28', $holidayWindow['starts_on']);
        $this->assertSame('2026-02-28', $holidayWindow['paycheck']['date']);
        $this->assertSame(2875.5, $holidayWindow['paycheck']['amount']);
        $this->assertSame(1675.5, $holidayWindow['planned_leftover']);
    }

    public function test_income_stays_in_the_scheduled_month_when_the_paid_date_moves(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);

        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 2875.5,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $query = app(CategorySpendQuery::class);

        $this->assertSame(
            2875.5,
            $query->incomeTotalForUser(
                $user->id,
                Carbon::parse('2026-03-01'),
                Carbon::parse('2026-04-01'),
            ),
        );
        $this->assertSame(
            3000.0,
            $query->incomeTotalForUser(
                $user->id,
                Carbon::parse('2026-02-01'),
                Carbon::parse('2026-03-01'),
            ),
        );
    }

    public function test_non_owner_cannot_adjust_an_occurrence(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);
        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');
        $other = User::factory()->create();

        $this->actingAs($other)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 1,
            ])
            ->assertNotFound();
    }

    public function test_resolved_occurrence_cannot_be_adjusted(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);
        $occurrence = $this->occurrenceFor($paycheck, '2026-03-01');
        $occurrence->update(['status' => PlannedOccurrence::STATUS_RESOLVED]);

        $this->actingAs($user)
            ->patch(route('plans.occurrences.update', $occurrence), [
                'expected_date' => '2026-02-28',
                'expected_amount' => 2875.5,
            ])
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: PlannedTemplate}
     */
    protected function paycheckSetup(): array
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
        ]);

        return [$user, $paycheck];
    }

    /**
     * @return array{0: User, 1: PlannedTemplate}
     */
    protected function billSetup(): array
    {
        $user = User::factory()->create();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $bill = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'expected_day' => 15,
            'expected_amount' => 140,
            'amount' => 140,
        ]);

        return [$user, $bill];
    }

    /**
     * @return array{0: User, 1: PlannedTemplate, 2: PlannedTemplate}
     */
    protected function paycheckWithRent(): array
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);
        $rent = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_day' => 1,
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);

        return [$user, $paycheck, $rent];
    }

    protected function occurrenceFor(PlannedTemplate $template, string $date): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('scheduled_date', $date)
            ->firstOrFail();
    }
}
