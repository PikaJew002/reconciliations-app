<?php

namespace Tests\Feature\Review;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Services\Plans\PaycheckLeftoverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewLeftoverTest extends TestCase
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

    public function test_leftover_page_focuses_the_current_paycheck_window(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $this->expense($user, 5000, '2026-07-10');
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-08',
            'from' => Account::CHECKING,
            'to' => Account::CREDIT_CARD,
            'kind' => PaycheckLeftoverService::ALLOCATION_CREDIT_CARD_PAYMENT,
        ]);

        $august = $this->occurrenceOn($paycheck, '2026-08-01');

        $this->actingAs($user)
            ->get('/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->where('selected_occurrence_id', $august->id)
                ->where('leftover_origin.month', '2026-07')
                ->where('leftover_origin.carry_over', 0)
                ->where('windows.0.paycheck.date', '2026-07-01')
                ->where('windows.0.is_current', false)
                ->where('windows.0.is_selected', false)
                ->where('windows.0.remaining', -2000)
                ->where('windows.0.spent', 5000)
                ->where('windows.1.paycheck.occurrence_id', $august->id)
                ->where('windows.1.is_current', true)
                ->where('windows.1.is_selected', true)
                ->where('windows.1.brought_forward', -2000)
                ->where('windows.1.planned_leftover', 3000)
                ->where('windows.1.spent', 0)
                ->where('windows.1.credit_card_payments', 200)
                ->where('windows.1.remaining', 800)
                ->where('windows.1.allocations.0.kind', PaycheckLeftoverService::ALLOCATION_CREDIT_CARD_PAYMENT)
                ->where('windows.1.allocations.0.amount', 200));
    }

    public function test_assigned_bills_are_listed_on_the_selected_window(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $rent = $this->rentBill($user);
        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)
            ->get('/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->where('windows.0.planned_leftover', 1800)
                ->where('windows.0.bills.0.name', 'Rent')
                ->where('windows.0.bills.0.amount', 1200)
                ->where('windows.0.unassigned_bills', [])
                ->where('windows.0.remaining', 1800));
    }

    public function test_occurrence_query_selects_a_past_paycheck_window(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $this->expense($user, 5000, '2026-07-10');

        $this->actingAs($user)->get('/review');

        $july = $this->occurrenceOn($paycheck, '2026-07-01');
        $august = $this->occurrenceOn($paycheck, '2026-08-01');

        $this->actingAs($user)
            ->get('/review?occurrence='.$july->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->where('selected_occurrence_id', $july->id)
                ->where('windows.0.paycheck.occurrence_id', $july->id)
                ->where('windows.0.is_selected', true)
                ->where('windows.0.is_current', false)
                ->where('windows.0.remaining', -2000)
                ->where('windows.1.paycheck.occurrence_id', $august->id)
                ->where('windows.1.is_selected', false)
                ->where('windows.1.is_current', true));
    }

    public function test_invalid_occurrence_falls_back_to_the_current_window(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $august = $this->occurrenceOn($paycheck, '2026-08-01');

        $this->actingAs($user)
            ->get('/review?occurrence=999999')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_occurrence_id', $august->id)
                ->where('windows.1.is_selected', true)
                ->where('windows.1.is_current', true));
    }

    public function test_unassigned_bills_are_listed_on_the_selected_window(): void
    {
        [$user] = $this->paycheckSetup();
        $this->rentBill($user);

        $this->actingAs($user)
            ->get('/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->where('windows.0.unassigned_bills.0.name', 'Rent')
                ->where('windows.0.unassigned_bills.0.amount', 1200)
                ->where('windows.0.spent', 1200)
                ->where('windows.0.planned_leftover', 3000)
                ->where('windows.0.remaining', 1800));
    }

    public function test_accounts_with_transactions_in_the_window_are_listed(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');

        $julyChecking = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'July Checking',
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $augustCard = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'August Card',
            'account_type' => Account::CREDIT_CARD,
            'is_active' => true,
        ]);
        $augustChecking = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'August Checking',
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $idle = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Idle Savings',
            'account_type' => Account::SAVINGS,
            'is_active' => true,
        ]);

        $this->expenseOn($user, $julyChecking, 10, '2026-07-10');
        $this->expenseOn($user, $augustCard, 20, '2026-08-08');
        $this->expenseOn($user, $augustChecking, 30, '2026-08-20');
        $this->expenseOn($user, $idle, 40, '2026-09-01');

        $july = $this->occurrenceOn($paycheck, '2026-07-01');

        $this->actingAs($user)
            ->get('/review?occurrence='.$july->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->has('windows.0.accounts', 1)
                ->where('windows.0.accounts.0.id', $julyChecking->id)
                ->where('windows.0.accounts.0.name', 'July Checking')
                ->where('windows.0.accounts.0.from', '2026-07-01')
                ->where('windows.0.accounts.0.to', '2026-07-31')
                ->has('windows.1.accounts', 2)
                ->where('windows.1.accounts.0.id', $augustCard->id)
                ->where('windows.1.accounts.0.name', 'August Card')
                ->where('windows.1.accounts.0.from', '2026-08-01')
                ->where('windows.1.accounts.0.to', '2026-08-31')
                ->where('windows.1.accounts.1.id', $augustChecking->id)
                ->where('windows.1.accounts.1.name', 'August Checking')
                ->where('windows.1.accounts.1.from', '2026-08-01')
                ->where('windows.1.accounts.1.to', '2026-08-31'));
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

    protected function startLeftoverFrom(User $user, string $date): void
    {
        $user->forceFill(['leftover_starts_on' => $date])->save();
    }

    protected function rentBill(User $user): PlannedTemplate
    {
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);

        return PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_day' => 1,
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);
    }

    protected function occurrenceOn(PlannedTemplate $paycheck, string $date): PlannedOccurrence
    {
        app(PaycheckLeftoverService::class)->windows($paycheck->user_id);

        return PlannedOccurrence::query()
            ->where('template_id', $paycheck->id)
            ->whereDate('expected_date', $date)
            ->firstOrFail();
    }

    /**
     * @param  array{
     *     amount: float,
     *     posted_at: string,
     *     from: string,
     *     to: string,
     *     kind?: string,
     *     status?: string
     * }  $attributes
     */
    protected function transferPair(User $user, array $attributes): TransactionTransferLink
    {
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $from = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => $attributes['from'],
        ]);
        $to = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => $attributes['to'],
        ]);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $from->id,
            'import_batch_id' => $batch->id,
            'amount' => -1 * $attributes['amount'],
            'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
            'posted_at' => $attributes['posted_at'],
            'description' => 'Transfer',
        ]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $to->id,
            'import_batch_id' => $batch->id,
            'amount' => $attributes['amount'],
            'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
            'posted_at' => $attributes['posted_at'],
            'description' => 'Transfer',
        ]);

        $metadata = ['source' => 'auto'];

        if (isset($attributes['kind'])) {
            $metadata['kind'] = $attributes['kind'];
        }

        return TransactionTransferLink::query()->create([
            'user_id' => $user->id,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => 90,
            'status' => $attributes['status'] ?? TransactionTransferLink::STATUS_CONFIRMED,
            'metadata' => $metadata,
        ]);
    }

    protected function expense(
        User $user,
        float $amount,
        string $postedAt,
        string $accountType = Account::CHECKING,
    ): BankTransaction {
        $account = Account::factory()->create([
            'account_type' => $accountType,
        ]);

        return $this->expenseOn($user, $account, $amount, $postedAt);
    }

    protected function expenseOn(
        User $user,
        Account $account,
        float $amount,
        string $postedAt,
    ): BankTransaction {
        $dining = Category::query()
            ->where('user_id', $user->id)
            ->where('kind', Category::KIND_EXPENSE)
            ->first() ?? Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        return BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1 * $amount,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => $postedAt,
        ]);
    }
}
