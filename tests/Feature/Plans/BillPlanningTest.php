<?php

namespace Tests\Feature\Plans;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillPlanningTest extends TestCase
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

    public function test_creating_a_bill_plan_generates_monthly_bill_occurrences(): void
    {
        $user = User::factory()->create();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $this->actingAs($user)
            ->post('/plans', [
                'name' => 'Electric',
                'category_id' => $utilities->id,
                'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
                'normalized_pattern' => 'DUKE ENERGY',
                'expected_day' => 15,
                'expected_amount' => 140,
                'lookback_days' => 7,
                'lookforward_days' => 3,
            ])
            ->assertRedirect(route('plans.index'));

        $template = PlannedTemplate::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($template);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $template->classification);
        $this->assertSame('duke energy', $template->normalized_pattern);
        $this->assertSame(140.0, (float) $template->amount);
        $this->assertSame(140.0, (float) $template->expected_amount);

        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $template->id)
                ->where('classification', BankTransaction::CLASSIFICATION_BILL)
                ->whereDate('expected_date', '2026-03-15')
                ->where('status', PlannedOccurrence::STATUS_PLANNED)
                ->exists(),
        );

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('bill_templates', 1)
                ->where('bill_templates.0.name', 'Electric')
                ->has('bill_occurrences', 1)
                ->where('bill_occurrences.0.status', PlannedOccurrence::STATUS_PLANNED)
                ->where('bill_occurrences.0.amount', 140)
                ->has('paycheck_templates', 0));
    }

    public function test_two_bill_plans_can_share_the_same_category(): void
    {
        $user = User::factory()->create();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $this->actingAs($user)->post('/plans', [
            'name' => 'Phone',
            'category_id' => $utilities->id,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'VERIZON',
            'expected_day' => 5,
            'expected_amount' => 80,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ])->assertRedirect(route('plans.index'));

        $this->actingAs($user)->post('/plans', [
            'name' => 'Electric',
            'category_id' => $utilities->id,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'DUKE ENERGY',
            'expected_day' => 15,
            'expected_amount' => 140,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ])->assertRedirect(route('plans.index'));

        $this->assertSame(2, PlannedTemplate::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            2,
            PlannedTemplate::query()
                ->where('user_id', $user->id)
                ->where('category_id', $utilities->id)
                ->count(),
        );

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('bill_templates', 2)
                ->has('bill_occurrences', 2));
    }

    public function test_create_form_includes_bill_source_transactions_and_matching_rule(): void
    {
        $user = User::factory()->create();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        $emptyBill = Category::factory()->for($user)->bill()->create(['name' => 'Rent']);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'duke energy',
            'amount' => 140,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-03-15',
            'description' => 'DUKE ENERGY 8821',
            'normalized_description' => 'duke energy 8821',
        ]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has("source_transactions.{$utilities->id}", 1)
                ->where("source_transactions.{$utilities->id}.0.id", $transaction->id)
                ->where("source_transactions.{$utilities->id}.0.expected_day", 15)
                ->where("source_transactions.{$utilities->id}.0.amount", 140)
                ->where("source_transactions.{$utilities->id}.0.match_mode", TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT)
                ->where("source_transactions.{$utilities->id}.0.normalized_pattern", 'duke energy')
                ->missing("source_transactions.{$emptyBill->id}"));
    }

    public function test_imported_debit_in_the_look_window_resolves_and_categorizes(): void
    {
        [$user, $utilities, $template] = $this->billSetup();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => null,
            'category_id' => null,
            'posted_at' => '2026-03-16',
            'description' => 'DUKE ENERGY 8821',
            'normalized_description' => 'duke energy 8821',
        ]);

        $result = app(PlannedOccurrenceMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['matched']);
        $this->assertDatabaseHas('planned_occurrences', [
            'template_id' => $template->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $transaction->id,
        ]);

        $transaction->refresh();
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $transaction->classification);
        $this->assertSame($utilities->id, $transaction->category_id);
    }

    public function test_debit_outside_the_window_does_not_match_and_one_inside_does(): void
    {
        [$user, $utilities, $template] = $this->billSetup();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $tooEarly = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-03-07',
            'description' => 'DUKE ENERGY TOO EARLY',
            'normalized_description' => 'duke energy too early',
        ]);

        $this->assertSame(0, app(PlannedOccurrenceMatcher::class)->matchForUser($user->id)['matched']);
        $this->assertDatabaseMissing('planned_occurrences', [
            'template_id' => $template->id,
            'bank_transaction_id' => $tooEarly->id,
        ]);

        $inWindow = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-03-08',
            'description' => 'DUKE ENERGY ON TIME',
            'normalized_description' => 'duke energy on time',
        ]);

        $this->assertSame(1, app(PlannedOccurrenceMatcher::class)->matchForUser($user->id)['matched']);
        $this->assertDatabaseHas('planned_occurrences', [
            'template_id' => $template->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $inWindow->id,
        ]);
    }

    public function test_manual_link_accepts_a_debit_and_rejects_a_credit(): void
    {
        [$user, $utilities, $template] = $this->billSetup();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($template);

        $occurrence = PlannedOccurrence::query()
            ->where('template_id', $template->id)
            ->whereDate('expected_date', '2026-03-15')
            ->first();

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 140.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'posted_at' => '2026-03-15',
            'description' => 'NOT A BILL',
        ]);

        $this->actingAs($user)
            ->post(route('plans.occurrences.link', $occurrence), [
                'bank_transaction_id' => $credit->id,
                'month' => '2026-03',
            ])
            ->assertStatus(422);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -138.5,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $utilities->id,
            'posted_at' => '2026-03-16',
            'description' => 'Something else',
        ]);

        $this->actingAs($user)
            ->post(route('plans.occurrences.link', $occurrence), [
                'bank_transaction_id' => $debit->id,
                'month' => '2026-03',
            ])
            ->assertRedirect();

        $occurrence->refresh();
        $this->assertSame(PlannedOccurrence::STATUS_RESOLVED, $occurrence->status);
        $this->assertSame($debit->id, $occurrence->bank_transaction_id);
    }

    public function test_paycheck_matching_is_unchanged_when_bill_plans_exist(): void
    {
        [$user, $salary, $paycheck] = $this->paycheckSetup();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        $bill = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'expected_day' => 15,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ]);

        app(PlannedOccurrenceGenerator::class)->syncTemplate($paycheck);
        app(PlannedOccurrenceGenerator::class)->syncTemplate($bill);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'category_id' => $salary->id,
            'posted_at' => '2026-03-01',
            'description' => 'ACME PAYROLL',
            'normalized_description' => 'acme payroll',
        ]);

        $result = app(PlannedOccurrenceMatcher::class)->matchForUser($user->id);

        $this->assertSame(1, $result['matched']);
        $this->assertDatabaseHas('planned_occurrences', [
            'template_id' => $paycheck->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
            'bank_transaction_id' => $credit->id,
        ]);
        $this->assertTrue(
            PlannedOccurrence::query()
                ->where('template_id', $bill->id)
                ->where('status', PlannedOccurrence::STATUS_PLANNED)
                ->whereNull('bank_transaction_id')
                ->exists(),
        );
    }

    /**
     * @return array{0: User, 1: Category, 2: PlannedTemplate}
     */
    protected function billSetup(): array
    {
        $user = User::factory()->create();
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        $template = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            'normalized_pattern' => 'duke energy',
            'amount' => 140,
            'expected_day' => 15,
            'expected_amount' => 140,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ]);

        return [$user, $utilities, $template];
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
