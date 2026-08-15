<?php

namespace Tests\Feature\Plans;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->where('templates.0.name', 'Mine')
                ->has('templates', 1));
    }

    public function test_plans_index_includes_recent_bill_transactions_for_amount_suggestions(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create();
        $rent = Category::factory()->for($user)->bill()->create(['name' => 'Rent']);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1250.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $rent->id,
            'posted_at' => '2026-02-01',
            'description' => 'LANDLORD',
        ]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where("bill_amount_options.{$rent->id}.0.amount", 1250)
                ->where("bill_amount_options.{$rent->id}.0.description", 'LANDLORD'));
    }

    public function test_template_bills_copy_to_occurrences_and_compute_leftover(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $rent = Category::factory()->for($user)->bill()->create(['name' => 'Rent']);
        $car = Category::factory()->for($user)->bill()->create(['name' => 'Car']);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Acme paycheck',
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'acme payroll',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
                'bills' => [
                    ['category_id' => $rent->id, 'expected_amount' => 1200],
                    ['category_id' => $car->id, 'expected_amount' => 350],
                ],
            ])
            ->assertRedirect(route('plans.index'));

        $template = PlannedTemplate::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($template);
        $this->assertSame(2, $template->bills()->count());

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('occurrences.0.paycheck_amount', 3000)
                ->where('occurrences.0.bills_total', 1550)
                ->where('occurrences.0.leftover', 1450)
                ->where('occurrences.0.bills_customized', false)
                ->has('occurrences.0.bills', 2));
    }

    public function test_paycheck_can_assign_multiple_bills_in_the_same_category(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Acme paycheck',
                'category_id' => $salary->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
                'normalized_pattern' => 'acme payroll',
                'expected_day' => 1,
                'expected_amount' => 3000,
                'lookback_days' => 7,
                'lookforward_days' => 3,
                'bills' => [
                    ['category_id' => $utilities->id, 'expected_amount' => 80],
                    ['category_id' => $utilities->id, 'expected_amount' => 140],
                ],
            ])
            ->assertRedirect(route('plans.index'));

        $template = PlannedTemplate::query()->where('user_id', $user->id)->first();
        $this->assertSame(2, $template->bills()->where('category_id', $utilities->id)->count());

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('occurrences.0.bills_total', 220)
                ->where('occurrences.0.leftover', 2780)
                ->has('occurrences.0.bills', 2));
    }

    public function test_occurrence_bill_override_is_not_wiped_by_template_sync(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $rent = Category::factory()->for($user)->bill()->create(['name' => 'Rent']);
        $car = Category::factory()->for($user)->bill()->create(['name' => 'Car']);

        $this->actingAs($user)->post('/plans', [
            'name' => 'Acme paycheck',
            'category_id' => $salary->id,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
            'lookback_days' => 7,
            'lookforward_days' => 3,
            'bills' => [
                ['category_id' => $rent->id, 'expected_amount' => 1200],
            ],
        ]);

        $template = PlannedTemplate::query()->where('user_id', $user->id)->first();
        $march = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('expected_date', '2026-03-01')
            ->first();

        $this->actingAs($user)
            ->patch(route('plans.occurrences.bills.update', $march), [
                'month' => '2026-03',
                'bills' => [
                    ['category_id' => $rent->id, 'expected_amount' => 1500],
                ],
            ])
            ->assertRedirect();

        $march->refresh();
        $this->assertTrue($march->bills_customized);
        $this->assertSame(1500.0, $march->assignedBillsTotal());

        $this->actingAs($user)->patch("/plans/{$template->id}?month=2026-03", [
            'name' => 'Acme paycheck',
            'category_id' => $salary->id,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
            'lookback_days' => 7,
            'lookforward_days' => 3,
            'is_active' => true,
            'bills' => [
                ['category_id' => $rent->id, 'expected_amount' => 1200],
                ['category_id' => $car->id, 'expected_amount' => 350],
            ],
        ]);

        $march->refresh()->load('bills');
        $this->assertTrue($march->bills_customized);
        $this->assertSame(1, $march->bills->count());
        $this->assertSame(1500.0, (float) $march->bills->first()->expected_amount);
        $this->assertSame(1500.0, $march->leftoverForExpenses());

        $april = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('expected_date', '2026-04-01')
            ->with('bills')
            ->first();

        $this->assertNotNull($april);
        $this->assertFalse($april->bills_customized);
        $this->assertSame(2, $april->bills->count());
        $this->assertSame(1550.0, $april->assignedBillsTotal());
        $this->assertSame(1450.0, $april->leftoverForExpenses());
    }

    public function test_resolved_paycheck_leftover_uses_actual_amount(): void
    {
        [$user, $salary, $template] = $this->paycheckSetup();
        $rent = Category::factory()->for($user)->bill()->create(['name' => 'Rent']);

        app(PlannedOccurrenceGenerator::class)->syncTemplateBills($template, [
            ['category_id' => $rent->id, 'expected_amount' => 1200],
        ]);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($template->fresh());

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
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('occurrences.0.paycheck_amount', 2987)
                ->where('occurrences.0.bills_total', 1200)
                ->where('occurrences.0.leftover', 1787));
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
