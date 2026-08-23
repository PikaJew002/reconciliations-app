<?php

namespace Tests\Feature\Plans;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PendingSpend;
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

class PaycheckLeftoverTest extends TestCase
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

    public function test_guests_are_rejected_from_leftover_endpoints(): void
    {
        $this->getJson(route('api.leftover.current'))
            ->assertUnauthorized();
        $this->getJson(route('api.leftover.index'))
            ->assertUnauthorized();
    }

    public function test_current_is_null_without_paycheck_plans(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJson(['leftover' => null]);
    }

    public function test_windows_chain_remaining_into_the_next_brought_forward(): void
    {
        [$user] = $this->paycheckSetup();

        $windows = $this->actingAs($user)
            ->getJson(route('api.leftover.index'))
            ->assertOk()
            ->json('windows');

        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(0, $july['brought_forward']);
        $this->assertEquals(3000, $july['planned_leftover']);
        $this->assertEquals(0, $july['spent']);
        $this->assertEquals(0, $july['allocated']);
        $this->assertEquals(3000, $july['remaining']);
        $this->assertEquals(3000, $august['brought_forward']);
        $this->assertEquals(3000, $august['planned_leftover']);
        $this->assertEquals(6000, $august['remaining']);
        $this->assertSame('2026-09-01', $august['next_paycheck']['date']);
    }

    public function test_overspend_debits_the_next_window(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 5000, '2026-07-10');

        $windows = $this->actingAs($user)
            ->getJson(route('api.leftover.index'))
            ->json('windows');

        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(5000, $july['spent']);
        $this->assertEquals(-2000, $july['remaining']);
        $this->assertEquals(-2000, $august['brought_forward']);
        $this->assertEquals(1000, $august['remaining']);
    }

    public function test_expenses_in_the_current_window_reduce_remaining(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 400, '2026-08-10');

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.starts_on', '2026-08-01')
            ->assertJsonPath('leftover.ends_before', '2026-09-01')
            ->assertJsonPath('leftover.spent', 400)
            ->assertJsonPath('leftover.remaining', 5600);
    }

    public function test_assigned_bill_transactions_are_not_counted_as_spend(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $rent = $this->rentBill($user);
        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)->getJson(route('api.leftover.current'));

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $rentTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1200.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'posted_at' => '2026-08-01',
        ]);

        PlannedOccurrence::query()
            ->where('template_id', $rent->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $rentTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.planned_leftover', 1800)
            ->assertJsonPath('leftover.spent', 0)
            ->assertJsonPath('leftover.unassigned_bills', []);
    }

    public function test_unassigned_bills_count_as_spend_and_are_listed(): void
    {
        [$user] = $this->paycheckSetup();
        $this->rentBill($user);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 1200)
            ->assertJsonPath('leftover.unassigned_bills.0.name', 'Rent')
            ->assertJsonPath('leftover.unassigned_bills.0.amount', 1200)
            ->assertJsonPath('leftover.unassigned_bills.0.date', '2026-08-01');
    }

    public function test_pending_spend_counts_in_the_paycheck_window(): void
    {
        [$user] = $this->paycheckSetup();
        $account = Account::factory()->create(['user_id' => $user->id]);

        PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'spent_at' => '2026-08-10 18:00:00',
            'amount' => 50,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'status' => PendingSpend::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 50)
            ->assertJsonPath('leftover.remaining', 5950);
    }

    public function test_resolved_paycheck_uses_the_actual_amount(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->actingAs($user)->getJson(route('api.leftover.current'));

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $paycheckTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'posted_at' => '2026-08-02',
        ]);

        PlannedOccurrence::query()
            ->where('template_id', $paycheck->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $paycheckTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.starts_on', '2026-08-02')
            ->assertJsonPath('leftover.paycheck.amount', 2987)
            ->assertJsonPath('leftover.planned_leftover', 2987);
    }

    public function test_next_paycheck_is_the_next_income_occurrence_of_any_template(): void
    {
        [$user, $first] = $this->paycheckSetup();
        $this->secondPaycheck($user, $first->category_id);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.starts_on', '2026-08-15')
            ->assertJsonPath('leftover.ends_before', '2026-09-01')
            ->assertJsonPath('leftover.next_paycheck.date', '2026-09-01')
            ->assertJsonPath('leftover.paycheck.name', 'Mid-month paycheck');
    }

    public function test_credit_card_payments_reduce_remaining_but_not_spent(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 500,
            'posted_at' => '2026-08-08',
            'from' => Account::CHECKING,
            'to' => Account::CREDIT_CARD,
            'kind' => PaycheckLeftoverService::ALLOCATION_CREDIT_CARD_PAYMENT,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 0)
            ->assertJsonPath('leftover.allocated', 500)
            ->assertJsonPath('leftover.credit_card_payments', 500)
            ->assertJsonPath('leftover.savings_transfers', 0)
            ->assertJsonPath('leftover.remaining', 5500);
    }

    public function test_savings_transfers_reduce_remaining_but_not_spent(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-08',
            'from' => Account::CHECKING,
            'to' => Account::SAVINGS,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 0)
            ->assertJsonPath('leftover.allocated', 200)
            ->assertJsonPath('leftover.savings_transfers', 200)
            ->assertJsonPath('leftover.credit_card_payments', 0)
            ->assertJsonPath('leftover.remaining', 5800);
    }

    public function test_savings_to_checking_transfers_increase_remaining(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-08',
            'from' => Account::SAVINGS,
            'to' => Account::CHECKING,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 0)
            ->assertJsonPath('leftover.allocated', -200)
            ->assertJsonPath('leftover.savings_transfers', -200)
            ->assertJsonPath('leftover.credit_card_payments', 0)
            ->assertJsonPath('leftover.remaining', 6200);
    }

    public function test_checking_to_checking_transfers_do_not_affect_leftover(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 250,
            'posted_at' => '2026-08-08',
            'from' => Account::CHECKING,
            'to' => Account::CHECKING,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.spent', 0)
            ->assertJsonPath('leftover.allocated', 0)
            ->assertJsonPath('leftover.remaining', 6000);
    }

    public function test_rejected_transfers_do_not_reduce_leftover(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 500,
            'posted_at' => '2026-08-08',
            'from' => Account::CHECKING,
            'to' => Account::SAVINGS,
            'status' => TransactionTransferLink::STATUS_REJECTED,
        ]);

        $this->actingAs($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJsonPath('leftover.allocated', 0)
            ->assertJsonPath('leftover.remaining', 6000);
    }

    public function test_dashboard_includes_current_paycheck_leftover_as_the_hero(): void
    {
        [$user] = $this->paycheckSetup();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $this->expense($user, 400, '2026-08-10');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_leftover.starts_on', '2026-08-01')
                ->where('paycheck_leftover.spent', 400)
                ->where('paycheck_leftover.remaining', 5600)
                ->has('summary.leftover_income')
                ->has('summary.vs_budget_difference'));
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

    protected function secondPaycheck(User $user, int $categoryId): PlannedTemplate
    {
        return PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'name' => 'Mid-month paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll mid',
            'expected_day' => 15,
            'expected_amount' => 3000,
        ]);
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

    protected function expense(User $user, float $amount, string $postedAt): BankTransaction
    {
        $dining = Category::query()
            ->where('user_id', $user->id)
            ->where('kind', Category::KIND_EXPENSE)
            ->first() ?? Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $account = Account::factory()->create();
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

    /**
     * @param  list<array<string, mixed>>  $windows
     * @return array<string, mixed>
     */
    protected function windowStarting(array $windows, string $startsOn): array
    {
        $window = collect($windows)->firstWhere('starts_on', $startsOn);

        $this->assertNotNull($window, "Missing leftover window starting {$startsOn}");

        return $window;
    }
}
