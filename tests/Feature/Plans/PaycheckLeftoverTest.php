<?php

namespace Tests\Feature\Plans;

use App\Http\Controllers\ApiTokens\ApiTokenController;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionAllocation;
use App\Models\TransactionCategorizationRule;
use App\Models\TransactionTransferLink;
use App\Models\User;
use App\Services\Plans\LeftoverOriginService;
use App\Services\Plans\PaycheckLeftoverService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
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
    }

    public function test_tokens_without_leftover_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $this->getJson(route('api.leftover.current'))
            ->assertForbidden();
    }

    public function test_pending_spend_and_amazon_tokens_cannot_read_leftover(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['pending-spend:create']);

        $this->getJson(route('api.leftover.current'))
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->getJson(route('api.leftover.current'))
            ->assertForbidden();
    }

    public function test_leftover_tokens_cannot_create_pending_spend(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_LEFTOVER_REPORTING]);

        $this->postJson(route('api.pending-spends.store'), [])
            ->assertForbidden();
    }

    public function test_current_is_null_without_paycheck_plans(): void
    {
        $user = User::factory()->create();

        $this->actingAsLeftoverReporter($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJson([
                'remaining' => null,
                'days_remaining' => null,
                'next_paycheck' => null,
            ]);
    }

    public function test_windows_chain_remaining_into_the_next_brought_forward(): void
    {
        [$user] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');

        $windows = $this->leftoverWindows($user);

        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(0, $july['brought_forward']);
        $this->assertEquals(3000, $july['planned_leftover']);
        $this->assertEquals(0, $july['spent']);
        $this->assertEquals(0, $july['allocated']);
        $this->assertEquals(3000, $july['paycheck_remaining']);
        $this->assertEquals(3000, $july['remaining']);
        $this->assertEquals(3000, $august['brought_forward']);
        $this->assertEquals(3000, $august['planned_leftover']);
        $this->assertEquals(3000, $august['paycheck_remaining']);
        $this->assertEquals(6000, $august['remaining']);
        $this->assertSame('2026-09-01', $august['next_paycheck']['date']);
    }

    public function test_overspend_debits_the_next_window(): void
    {
        [$user] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $this->expense($user, 5000, '2026-07-10');

        $windows = $this->leftoverWindows($user);

        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(5000, $july['spent']);
        $this->assertEquals(-2000, $july['paycheck_remaining']);
        $this->assertEquals(-2000, $july['remaining']);
        $this->assertEquals(-2000, $august['brought_forward']);
        $this->assertEquals(3000, $august['paycheck_remaining']);
        $this->assertEquals(1000, $august['remaining']);
    }

    public function test_widget_reports_this_paycheck_remaining_not_the_year_chain(): void
    {
        [$user] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $this->expense($user, 5000, '2026-07-10');

        $this->actingAsLeftoverReporter($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJson([
                'remaining' => '$3,000.00',
                'days_remaining' => 17,
                'next_paycheck' => 'Sep 1',
            ]);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(3000, $leftover['paycheck_remaining']);
        $this->assertEquals(1000, $leftover['remaining']);
        $this->assertEquals(-2000, $leftover['previous_paycheck_remaining']);
        $this->assertSame('Acme paycheck', $leftover['previous_paycheck']['name']);
        $this->assertSame('2026-07-01', $leftover['previous_paycheck']['date']);
    }

    public function test_expenses_in_the_current_window_reduce_remaining(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 400, '2026-08-10');

        $this->actingAsLeftoverReporter($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJson([
                'remaining' => '$2,600.00',
                'days_remaining' => 17,
                'next_paycheck' => 'Sep 1',
            ]);
    }

    public function test_current_formats_negative_remaining_with_minus_before_dollar(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 6500, '2026-08-10');

        $this->actingAsLeftoverReporter($user)
            ->getJson(route('api.leftover.current'))
            ->assertOk()
            ->assertJson([
                'remaining' => '-$3,500.00',
                'days_remaining' => 17,
                'next_paycheck' => 'Sep 1',
            ]);
    }

    public function test_assigned_bill_transactions_are_not_counted_as_spend(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $rent = $this->rentBill($user);
        $paycheck->assignedBills()->sync([$rent->id]);

        $this->leftoverCurrent($user);

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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(1800, $leftover['planned_leftover']);
        $this->assertEquals(0, $leftover['spent']);
        $this->assertSame([], $leftover['unassigned_bills']);
    }

    public function test_unassigned_bills_count_as_spend_and_are_listed(): void
    {
        [$user] = $this->paycheckSetup();
        $this->rentBill($user);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(1200, $leftover['spent']);
        $this->assertSame('Rent', $leftover['unassigned_bills'][0]['name']);
        $this->assertEquals(1200, $leftover['unassigned_bills'][0]['amount']);
        $this->assertSame('2026-08-01', $leftover['unassigned_bills'][0]['date']);
    }

    public function test_pending_spend_counts_in_the_paycheck_window(): void
    {
        [$user] = $this->paycheckSetup();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CHECKING,
        ]);

        PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'spent_at' => '2026-08-10 18:00:00',
            'amount' => 50,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'status' => PendingSpend::STATUS_PENDING,
        ]);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(50, $leftover['spent']);
        $this->assertEquals(2950, $leftover['paycheck_remaining']);
        $this->assertEquals(2950, $leftover['remaining']);
    }

    public function test_resolved_paycheck_uses_the_actual_amount(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->leftoverCurrent($user);

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

        $leftover = $this->leftoverCurrent($user);

        $this->assertSame('2026-08-02', $leftover['starts_on']);
        $this->assertEquals(2987, $leftover['paycheck']['amount']);
        $this->assertEquals(2987, $leftover['planned_leftover']);
    }

    public function test_next_paycheck_is_the_next_income_occurrence_of_any_template(): void
    {
        [$user, $first] = $this->paycheckSetup();
        $this->secondPaycheck($user, $first->category_id);

        $leftover = $this->leftoverCurrent($user);

        $this->assertSame('2026-08-15', $leftover['starts_on']);
        $this->assertSame('2026-09-01', $leftover['ends_before']);
        $this->assertSame('2026-09-01', $leftover['next_paycheck']['date']);
        $this->assertSame('Mid-month paycheck', $leftover['paycheck']['name']);
    }

    public function test_default_origin_is_the_first_paycheck_in_the_current_month(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 5000, '2026-07-10');

        $windows = $this->leftoverWindows($user);

        $this->assertNull(collect($windows)->firstWhere('starts_on', '2026-07-01'));

        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(0, $august['brought_forward']);
        $this->assertEquals(3000, $august['planned_leftover']);
        $this->assertEquals(0, $august['spent']);
        $this->assertEquals(3000, $august['remaining']);
        $this->assertSame('2026-08-01', $user->fresh()->leftover_starts_on->toDateString());
    }

    public function test_locked_origin_does_not_move_into_the_next_month(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);

        $this->assertSame('2026-08-01', $user->fresh()->leftover_starts_on->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00'));

        $windows = $this->leftoverWindows($user);

        $this->assertSame('2026-08-01', $user->fresh()->leftover_starts_on->toDateString());
        $this->assertEquals(0, $this->windowStarting($windows, '2026-08-01')['brought_forward']);
        $this->assertEquals(3000, $this->windowStarting($windows, '2026-09-01')['brought_forward']);
    }

    public function test_spend_before_the_origin_paycheck_is_ignored(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 5000, '2026-07-10');
        $this->expense($user, 400, '2026-08-10');

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['brought_forward']);
        $this->assertEquals(400, $leftover['spent']);
        $this->assertEquals(2600, $leftover['paycheck_remaining']);
        $this->assertEquals(2600, $leftover['remaining']);
    }

    public function test_early_posted_origin_paycheck_is_still_the_start_of_leftover(): void
    {
        [$user, $paycheck] = $this->paycheckSetup();
        $this->leftoverWindows($user);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $paycheckTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 3000.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'posted_at' => '2026-07-30',
        ]);

        PlannedOccurrence::query()
            ->where('template_id', $paycheck->id)
            ->whereDate('expected_date', '2026-08-01')
            ->update([
                'bank_transaction_id' => $paycheckTx->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
            ]);

        $windows = $this->leftoverWindows($user);
        $origin = $this->windowStarting($windows, '2026-07-30');

        $this->assertNull(collect($windows)->firstWhere('starts_on', '2026-07-01'));
        $this->assertEquals(0, $origin['brought_forward']);
        $this->assertSame('2026-07-30', $origin['starts_on']);
        $this->assertSame(
            '2026-08-01',
            app(LeftoverOriginService::class)->payload($user->id)['paycheck']['date'],
        );
    }

    public function test_user_can_restart_leftover_from_an_earlier_planned_month(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 5000, '2026-07-10');
        $this->leftoverWindows($user);

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-08']))
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-07',
                'view_month' => '2026-08',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']));

        $this->assertSame('2026-07-01', $user->fresh()->leftover_starts_on->toDateString());

        $windows = $this->leftoverWindows($user);
        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(0, $july['brought_forward']);
        $this->assertEquals(5000, $july['spent']);
        $this->assertEquals(-2000, $july['remaining']);
        $this->assertEquals(-2000, $august['brought_forward']);
    }

    public function test_plans_page_includes_leftover_origin(): void
    {
        [$user] = $this->paycheckSetup();

        $this->actingAs($user)
            ->get(route('plans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('leftover_origin.month', '2026-08')
                ->where('leftover_origin.starts_on', '2026-08-01')
                ->where('leftover_origin.paycheck.date', '2026-08-01')
                ->where('leftover_origin.paycheck.name', 'Acme paycheck')
                ->where('leftover_origin.carry_over', 0)
                ->has('leftover_origin.months'));
    }

    public function test_windows_seed_brought_forward_from_carry_over(): void
    {
        [$user] = $this->paycheckSetup();
        $this->startLeftoverFrom($user, '2026-07-01');
        $user->forceFill(['leftover_carry_over' => 800])->save();

        $windows = $this->leftoverWindows($user);
        $july = $this->windowStarting($windows, '2026-07-01');
        $august = $this->windowStarting($windows, '2026-08-01');

        $this->assertEquals(800, $july['brought_forward']);
        $this->assertEquals(3000, $july['paycheck_remaining']);
        $this->assertEquals(3800, $july['remaining']);
        $this->assertEquals(3800, $august['brought_forward']);
        $this->assertEquals(6800, $august['remaining']);
    }

    public function test_user_can_save_leftover_carry_over(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);

        $this->actingAs($user)
            ->from(route('plans.index'))
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-08',
                'view_month' => '2026-08',
                'carry_over' => 800.5,
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']));

        $this->assertEquals(800.5, (float) $user->fresh()->leftover_carry_over);
        $this->assertEquals(800.5, $this->windowStarting($this->leftoverWindows($user), '2026-08-01')['brought_forward']);
    }

    public function test_negative_carry_over_is_allowed(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);

        $this->actingAs($user)
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-08',
                'carry_over' => -250,
            ])
            ->assertRedirect();

        $this->assertEquals(-250, (float) $user->fresh()->leftover_carry_over);

        $august = $this->windowStarting($this->leftoverWindows($user), '2026-08-01');

        $this->assertEquals(-250, $august['brought_forward']);
        $this->assertEquals(2750, $august['remaining']);
    }

    public function test_empty_carry_over_defaults_to_zero(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);
        $user->forceFill(['leftover_carry_over' => 800])->save();

        $this->actingAs($user)
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-08',
                'carry_over' => '',
            ])
            ->assertRedirect();

        $this->assertEquals(0, (float) $user->fresh()->leftover_carry_over);
    }

    public function test_changing_start_month_without_carry_over_resets_it(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);
        $user->forceFill(['leftover_carry_over' => 800])->save();

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-08']))
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-07',
                'view_month' => '2026-08',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']));

        $this->assertSame('2026-07-01', $user->fresh()->leftover_starts_on->toDateString());
        $this->assertEquals(0, (float) $user->fresh()->leftover_carry_over);
        $this->assertEquals(0, $this->windowStarting($this->leftoverWindows($user), '2026-07-01')['brought_forward']);
    }

    public function test_changing_start_month_can_include_a_new_carry_over(): void
    {
        [$user] = $this->paycheckSetup();
        $this->leftoverWindows($user);
        $user->forceFill(['leftover_carry_over' => 800])->save();

        $this->actingAs($user)
            ->from(route('plans.index', ['month' => '2026-08']))
            ->put(route('plans.leftover-origin.update'), [
                'month' => '2026-07',
                'view_month' => '2026-08',
                'carry_over' => 500,
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-08']));

        $this->assertSame('2026-07-01', $user->fresh()->leftover_starts_on->toDateString());
        $this->assertEquals(500, (float) $user->fresh()->leftover_carry_over);
        $this->assertEquals(500, $this->windowStarting($this->leftoverWindows($user), '2026-07-01')['brought_forward']);
    }

    public function test_creating_a_paycheck_plan_locks_leftover_to_the_current_month(): void
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

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
            ])
            ->assertRedirect(route('plans.index'));

        $this->assertSame('2026-08-01', $user->fresh()->leftover_starts_on->toDateString());
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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(500, $leftover['allocated']);
        $this->assertEquals(500, $leftover['credit_card_payments']);
        $this->assertEquals(0, $leftover['savings_transfers']);
        $this->assertEquals(2500, $leftover['remaining']);
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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(200, $leftover['allocated']);
        $this->assertEquals(200, $leftover['savings_transfers']);
        $this->assertEquals(0, $leftover['credit_card_payments']);
        $this->assertEquals(2800, $leftover['remaining']);
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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(-200, $leftover['allocated']);
        $this->assertEquals(-200, $leftover['savings_transfers']);
        $this->assertEquals(0, $leftover['credit_card_payments']);
        $this->assertEquals(3200, $leftover['remaining']);
    }

    public function test_savings_to_checking_increases_remaining_the_same_way_a_card_payment_decreases_it(): void
    {
        [$user] = $this->paycheckSetup();
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-08',
            'from' => Account::SAVINGS,
            'to' => Account::CHECKING,
        ]);
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-09',
            'from' => Account::CHECKING,
            'to' => Account::CREDIT_CARD,
            'kind' => PaycheckLeftoverService::ALLOCATION_CREDIT_CARD_PAYMENT,
        ]);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(0, $leftover['allocated']);
        $this->assertEquals(200, $leftover['credit_card_payments']);
        $this->assertEquals(-200, $leftover['savings_transfers']);
        $this->assertEquals(3000, $leftover['paycheck_remaining']);
        $this->assertEquals(3000, $leftover['remaining']);
    }

    public function test_credit_card_charges_do_not_reduce_leftover(): void
    {
        [$user] = $this->paycheckSetup();
        $this->expense($user, 400, '2026-08-10', Account::CREDIT_CARD);
        $this->expense($user, 75, '2026-08-11', Account::CHECKING);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(75, $leftover['spent']);
        $this->assertEquals(0, $leftover['allocated']);
        $this->assertEquals(2925, $leftover['paycheck_remaining']);
        $this->assertEquals(2925, $leftover['remaining']);
    }

    public function test_credit_card_pending_spend_does_not_reduce_leftover(): void
    {
        [$user] = $this->paycheckSetup();
        $card = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CREDIT_CARD,
        ]);

        PendingSpend::factory()->creditCard()->create([
            'user_id' => $user->id,
            'account_id' => $card->id,
            'spent_at' => '2026-08-10 18:00:00',
            'amount' => 50,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'status' => PendingSpend::STATUS_PENDING,
        ]);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(3000, $leftover['remaining']);
    }

    public function test_order_components_matched_to_a_credit_card_do_not_reduce_leftover(): void
    {
        [$user] = $this->paycheckSetup();
        $card = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $bank = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $card->id,
            'import_batch_id' => $batch->id,
            'amount' => -42.5,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'posted_at' => '2026-08-10',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-10',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'amount' => 42.5,
        ]);
        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $bank->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 42.5,
        ]);

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(3000, $leftover['remaining']);
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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['spent']);
        $this->assertEquals(0, $leftover['allocated']);
        $this->assertEquals(3000, $leftover['remaining']);
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

        $leftover = $this->leftoverCurrent($user);

        $this->assertEquals(0, $leftover['allocated']);
        $this->assertEquals(3000, $leftover['remaining']);
    }

    public function test_dashboard_includes_current_paycheck_leftover_as_the_hero(): void
    {
        [$user] = $this->paycheckSetup();
        BudgetYear::factory()->for($user)->current()->starting('2026-07')->create();
        $this->expense($user, 400, '2026-08-10');
        $this->transferPair($user, [
            'amount' => 150,
            'posted_at' => '2026-08-08',
            'from' => Account::SAVINGS,
            'to' => Account::CHECKING,
        ]);
        $this->transferPair($user, [
            'amount' => 200,
            'posted_at' => '2026-08-09',
            'from' => Account::CHECKING,
            'to' => Account::CREDIT_CARD,
            'kind' => PaycheckLeftoverService::ALLOCATION_CREDIT_CARD_PAYMENT,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('paycheck_leftover.starts_on', '2026-08-01')
                ->where('paycheck_leftover.spent', 400)
                ->where('paycheck_leftover.credit_card_payments', 200)
                ->where('paycheck_leftover.savings_transfers', -150)
                ->where('paycheck_leftover.allocated', 50)
                ->where('paycheck_leftover.paycheck_remaining', 2550)
                ->where('paycheck_leftover.remaining', 2550)
                ->where('paycheck_leftover.brought_forward', 0)
                ->where('paycheck_leftover.previous_paycheck_remaining', null)
                ->where('leftover_origin.month', '2026-08')
                ->where('leftover_origin.paycheck.date', '2026-08-01')
                ->has('month_report.summary.leftover_income')
                ->has('month_report.summary.vs_budget_difference')
                ->has('year_report.summary.leftover_income'));
    }

    protected function actingAsLeftoverReporter(User $user): static
    {
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_LEFTOVER_REPORTING]);

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function leftoverWindows(User $user): array
    {
        return app(PaycheckLeftoverService::class)->windows($user->id);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function leftoverCurrent(User $user): ?array
    {
        return app(PaycheckLeftoverService::class)->current($user->id);
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

    protected function expense(
        User $user,
        float $amount,
        string $postedAt,
        string $accountType = Account::CHECKING,
    ): BankTransaction {
        $dining = Category::query()
            ->where('user_id', $user->id)
            ->where('kind', Category::KIND_EXPENSE)
            ->first() ?? Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $account = Account::factory()->create([
            'account_type' => $accountType,
        ]);
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
