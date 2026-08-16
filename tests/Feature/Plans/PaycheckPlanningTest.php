<?php

namespace Tests\Feature\Plans;

use App\Jobs\MatchPlannedOccurrences;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceMatchRun;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaycheckPlanningTest extends TestCase
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

    public function test_jan_31_template_clamps_february_occurrence_to_the_28th(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create();
        $template = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'expected_day' => 31,
        ]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-01-31')
                ->exists(),
        );
        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-02-28')
                ->exists(),
        );
    }

    public function test_early_posted_paycheck_matches_next_month_occurrence(): void
    {
        [$user, $salary, $template] = $this->paycheckSetup();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-02-28',
            'description' => 'ACME PAYROLL',
            'normalized_description' => 'acme payroll',
        ]);

        $result = app(PlannedOccurrenceMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['matched']);
        $this->assertDatabaseHas('planned_occurrences', [
            'template_id' => $template->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $transaction->id,
        ]);
        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-03-01')
                ->where('bank_transaction_id', $transaction->id)
                ->exists(),
        );

        $query = app(CategorySpendQuery::class);

        $this->assertSame(
            [$salary->id => 2987.0],
            $query->incomeCategoryTotalsForUser(
                $user->id,
                Carbon::parse('2026-03-01'),
                Carbon::parse('2026-04-01'),
            ),
        );
        $this->assertSame(
            [$salary->id => 3000.0],
            $query->incomeCategoryTotalsForUser(
                $user->id,
                Carbon::parse('2026-02-01'),
                Carbon::parse('2026-03-01'),
            ),
        );
        $this->assertSame(
            2987.0,
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

    public function test_still_planned_occurrence_counts_toward_leftover_then_uses_actual_without_double_count(): void
    {
        [$user, $salary, $template] = $this->paycheckSetup();
        BudgetYear::factory()->for($user)->current()->starting('2026-01')->create();

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('summary.income', 3000)
                ->where('summary.income_planned', 3000)
                ->where('summary.income_received', 0)
                ->where('summary.leftover_income', 3000));

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-02-28',
            'description' => 'ACME PAYROLL',
            'normalized_description' => 'acme payroll',
        ]);

        app(PlannedOccurrenceMatcher::class)->matchForUser($user->id);

        $this->actingAs($user)
            ->get('/?view=month&month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('summary.income', 2987)
                ->where('summary.income_planned', 0)
                ->where('summary.income_received', 2987)
                ->where('summary.leftover_income', 2987)
                ->where('total_income', 2987));
    }

    public function test_user_can_create_paycheck_plan_and_manually_link_a_credit(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Acme paycheck',
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'ACME PAYROLL',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
            ])
            ->assertRedirect(route('plans.index'));

        $template = PlannedTemplate::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($template);
        $this->assertSame('acme payroll', $template->normalized_pattern);
        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->whereDate('expected_date', '2026-03-01')
                ->where('status', PlannedOccurrence::STATUS_PLANNED)
                ->exists(),
        );

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 3010.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-03-02',
            'description' => 'Something else',
        ]);

        $occurrence = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('expected_date', '2026-03-01')
            ->first();

        $this->actingAs($user)
            ->post(route('plans.occurrences.link', $occurrence), [
                'bank_transaction_id' => $transaction->id,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $occurrence->refresh();
        $this->assertSame(PlannedOccurrence::STATUS_RESOLVED, $occurrence->status);
        $this->assertSame($transaction->id, $occurrence->bank_transaction_id);
    }

    public function test_creating_a_paycheck_plan_dispatches_occurrence_matching(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Acme paycheck',
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'ACME PAYROLL',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
            ])
            ->assertRedirect(route('plans.index'));

        $run = PlannedOccurrenceMatchRun::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);

        Queue::assertPushed(
            MatchPlannedOccurrences::class,
            fn (MatchPlannedOccurrences $job) => $job->userId === $user->id
                && $job->matchRunId === $run->id,
        );
    }

    public function test_updating_a_paycheck_plan_dispatches_occurrence_matching(): void
    {
        Queue::fake();

        [$user, $salary, $template] = $this->paycheckSetup();

        $this->actingAs($user)
            ->patch("/plans/{$template->id}", [
                'name' => $template->name,
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'acme payroll',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
                'is_active' => true,
            ])
            ->assertRedirect();

        $run = PlannedOccurrenceMatchRun::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($run);

        Queue::assertPushed(
            MatchPlannedOccurrences::class,
            fn (MatchPlannedOccurrences $job) => $job->userId === $user->id
                && $job->matchRunId === $run->id,
        );
    }

    public function test_creating_a_paycheck_plan_matches_an_existing_credit(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 3000.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-03-01',
            'description' => 'ACME PAYROLL',
            'normalized_description' => 'acme payroll',
        ]);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Acme paycheck',
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'ACME PAYROLL',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
            ])
            ->assertRedirect(route('plans.index'));

        $this->assertDatabaseHas('planned_occurrences', [
            'user_id' => $user->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $transaction->id,
        ]);

        $run = PlannedOccurrenceMatchRun::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['matched']);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('active_match_runs', 1)
                ->where('active_match_runs.0.id', $run->id)
                ->where('active_match_runs.0.status', 'completed')
                ->where('active_match_runs.0.metadata.matched', 1));
    }

    public function test_plans_index_includes_in_progress_match_runs(): void
    {
        $user = User::factory()->create();
        $run = PlannedOccurrenceMatchRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
        ]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('active_match_runs', 1)
                ->where('active_match_runs.0.id', $run->id)
                ->where('active_match_runs.0.status', 'processing'));
    }

    public function test_plans_index_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create();
        $otherSalary = Category::factory()->for($other)->income()->create();

        PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Mine',
        ]);
        PlannedTemplate::factory()->create([
            'user_id' => $other->id,
            'category_id' => $otherSalary->id,
            'name' => 'Theirs',
        ]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('paycheck_templates.0.name', 'Mine')
                ->has('paycheck_templates', 1)
                ->has('bill_templates', 0));
    }

    public function test_create_form_includes_category_source_transactions_and_matching_rule(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $emptyIncome = Category::factory()->for($user)->income()->create(['name' => 'Bonus']);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'amount' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 3010.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-03-01',
            'description' => 'ACME PAYROLL',
            'normalized_description' => 'acme payroll',
        ]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has("source_transactions.{$salary->id}", 1)
                ->where("source_transactions.{$salary->id}.0.id", $transaction->id)
                ->where("source_transactions.{$salary->id}.0.expected_day", 1)
                ->where("source_transactions.{$salary->id}.0.amount", 3010)
                ->where("source_transactions.{$salary->id}.0.match_mode", TransactionCategorizationRule::MATCH_DESCRIPTION)
                ->where("source_transactions.{$salary->id}.0.normalized_pattern", 'acme payroll')
                ->missing("source_transactions.{$emptyIncome->id}"));
    }

    /**
     * @return array{0: User, 1: Category, 2: PlannedTemplate}
     */
    protected function paycheckSetup(): array
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $template = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ]);

        return [$user, $salary, $template];
    }
}
