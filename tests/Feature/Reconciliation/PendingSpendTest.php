<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\MatchPendingSpends;
use App\Jobs\RunUserReconciliationPipeline;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\ReconciliationRun;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Models\VenmoActivity;
use App\Services\Budgets\BudgetProgressService;
use App\Services\Plans\PlannedOccurrenceMatcher;
use App\Services\Reconciliation\CreditCardPaymentPairingService;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use App\Services\Reconciliation\PendingSpendMatcher;
use App\Services\Reconciliation\PendingSpendService;
use App\Services\Reconciliation\ProductMatchingService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\TransactionCategorizationService;
use App\Services\Reconciliation\TransferPairingService;
use App\Services\Reconciliation\VenmoActivityMatcher;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PendingSpendTest extends TestCase
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

    public function test_uncategorized_pending_and_needs_review_count_as_expense_and_reduce_leftover(): void
    {
        $context = $this->context();
        $service = app(PendingSpendService::class);

        $service->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 40.00,
        ]);

        PendingSpend::factory()->create([
            'user_id' => $context['user']->id,
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
            'spent_at' => '2026-08-12 09:00:00',
            'amount' => 12.25,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'status' => PendingSpend::STATUS_NEEDS_REVIEW,
            'review_reason' => PendingSpend::REVIEW_NOT_FOUND,
        ]);

        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');
        $query = app(CategorySpendQuery::class);

        $this->assertSame(52.25, $query->uncategorizedExpenseSpendForUser($context['user']->id, $from, $to));
        $this->assertSame(0.0, $query->uncategorizedBillSpendForUser($context['user']->id, $from, $to));

        $progress = app(BudgetProgressService::class)->build($context['user']->id, 'month', '2026-08');

        $this->assertSame(52.25, $progress['summary']['expenses']);
        $this->assertSame(-52.25, $progress['summary']['vs_leftover_difference']);
    }

    public function test_categorized_pending_hits_category_immediately(): void
    {
        $context = $this->context();
        $dining = Category::factory()->for($context['user'])->expense()->create(['name' => 'Dining']);

        app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'category_id' => $dining->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 18.75,
        ]);

        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');
        $totals = app(CategorySpendQuery::class)->categoryTotalsForUser($context['user']->id, $from, $to);

        $this->assertSame(18.75, $totals[$dining->id]);
        $this->assertSame(0.0, app(CategorySpendQuery::class)->uncategorizedExpenseSpendForUser($context['user']->id, $from, $to));
    }

    public function test_after_resolve_spend_comes_from_bank_transaction_not_pending(): void
    {
        $context = $this->context();
        $dining = Category::factory()->for($context['user'])->expense()->create();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'category_id' => $dining->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 18.75,
        ]);

        $transaction = $this->debit($context, [
            'amount' => -18.75,
            'posted_at' => '2026-08-12',
            'transaction_date' => '2026-08-10',
            'merchant_id' => $context['merchant']->id,
        ]);

        app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $pending->refresh();
        $transaction->refresh();
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');
        $query = app(CategorySpendQuery::class);

        $this->assertSame(PendingSpend::STATUS_RESOLVED, $pending->status);
        $this->assertSame($transaction->id, $pending->bank_transaction_id);
        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $transaction->classification);
        $this->assertSame($dining->id, $transaction->category_id);
        $this->assertSame($context['merchant']->id, $transaction->merchant_id);
        $this->assertSame(18.75, $query->categoryTotalsForUser($context['user']->id, $from, $to)[$dining->id]);
        $this->assertCount(0, PendingSpend::query()->whereIn('status', PendingSpend::unmatchedStatuses())->get());
    }

    public function test_month_handoff_moves_spend_from_swipe_month_to_posted_month(): void
    {
        $context = $this->context();
        $dining = Category::factory()->for($context['user'])->expense()->create();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'category_id' => $dining->id,
            'spent_at' => '2026-08-31 21:00:00',
            'amount' => 25.00,
        ]);

        $query = app(CategorySpendQuery::class);
        $augustFrom = Carbon::parse('2026-08-01');
        $augustTo = Carbon::parse('2026-09-01');
        $septemberFrom = Carbon::parse('2026-09-01');
        $septemberTo = Carbon::parse('2026-10-01');

        $this->assertSame(25.00, $query->categoryTotalsForUser($context['user']->id, $augustFrom, $augustTo)[$dining->id]);
        $this->assertArrayNotHasKey($dining->id, $query->categoryTotalsForUser($context['user']->id, $septemberFrom, $septemberTo));

        $transaction = $this->debit($context, [
            'amount' => -25.00,
            'posted_at' => '2026-09-02',
            'transaction_date' => '2026-09-02',
            'merchant_id' => $context['merchant']->id,
        ]);

        app(PendingSpendService::class)->link($pending, $transaction);

        $this->assertArrayNotHasKey($dining->id, $query->categoryTotalsForUser($context['user']->id, $augustFrom, $augustTo));
        $this->assertSame(25.00, $query->categoryTotalsForUser($context['user']->id, $septemberFrom, $septemberTo)[$dining->id]);
    }

    public function test_exact_amount_matches_and_one_cent_difference_does_not(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.50,
        ]);

        $this->debit($context, [
            'amount' => -12.51,
            'posted_at' => '2026-08-11',
            'merchant_id' => $context['merchant']->id,
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(PendingSpend::STATUS_PENDING, $pending->fresh()->status);

        $match = $this->debit($context, [
            'amount' => -12.50,
            'posted_at' => '2026-08-11',
            'merchant_id' => $context['merchant']->id,
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(PendingSpend::STATUS_RESOLVED, $pending->fresh()->status);
        $this->assertSame($match->id, $pending->fresh()->bank_transaction_id);
    }

    public function test_venmo_pending_matches_bank_debit_without_merchant(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'venmo' => true,
            'spent_at' => '2026-08-10 19:11:00',
            'amount' => 250.00,
        ]);

        $this->assertNull($pending->merchant_id);

        $transaction = $this->debit($context, [
            'amount' => -250.00,
            'posted_at' => '2026-08-12',
            'description' => 'VENMO PURCHASE 1051937135825',
            'normalized_description' => 'venmo purchase 1051937135825',
            'merchant_id' => null,
        ]);
        $activity = VenmoActivity::factory()->cardPayment($context['account']->last_four, -250.00)->create([
            'user_id' => $context['user']->id,
            'import_batch_id' => $context['batch']->id,
            'occurred_at' => '2026-08-10 19:11:43',
            'to_name' => 'Tyler Adams',
            'note' => 'Extreme',
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $pending->refresh();
        $transaction->refresh();

        $this->assertSame(1, $result['matched']);
        $this->assertSame(PendingSpend::STATUS_RESOLVED, $pending->status);
        $this->assertSame($transaction->id, $pending->bank_transaction_id);
        $this->assertSame($activity->id, $pending->venmo_activity_id);
        $this->assertNull($transaction->merchant_id);
        $this->assertSame('ignored', $transaction->status);
        $this->assertDatabaseMissing('merchants', [
            'user_id' => $context['user']->id,
            'normalized_name' => 'venmo',
        ]);
    }

    public function test_two_exact_same_amount_candidates_are_ambiguous(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 09:00:00',
            'amount' => 12.50,
        ]);

        $this->debit($context, [
            'amount' => -12.50,
            'posted_at' => '2026-08-10',
            'merchant_id' => null,
        ]);
        $this->debit($context, [
            'amount' => -12.50,
            'posted_at' => '2026-08-11',
            'merchant_id' => null,
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $pending->refresh();

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(PendingSpend::STATUS_NEEDS_REVIEW, $pending->status);
        $this->assertSame(PendingSpend::REVIEW_AMBIGUOUS, $pending->review_reason);
        $this->assertNull($pending->bank_transaction_id);
    }

    public function test_covering_bank_import_without_match_flags_not_found(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.50,
        ]);

        $this->debit($context, [
            'amount' => -9.99,
            'posted_at' => '2026-08-14',
            'transaction_date' => '2026-08-14',
        ]);

        $result = (new MatchPendingSpends($context['user']->id, $context['batch']->id))
            ->handle(app(PendingSpendMatcher::class));

        $pending->refresh();

        $this->assertSame(0, $result['matched']);
        $this->assertSame(1, $result['flagged']);
        $this->assertSame(PendingSpend::STATUS_NEEDS_REVIEW, $pending->status);
        $this->assertSame(PendingSpend::REVIEW_NOT_FOUND, $pending->review_reason);
    }

    public function test_later_import_can_resolve_needs_review_row(): void
    {
        $context = $this->context();
        $pending = PendingSpend::factory()->needsReview()->create([
            'user_id' => $context['user']->id,
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.50,
            'card_last_four' => $context['account']->last_four,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
        ]);

        $transaction = $this->debit($context, [
            'amount' => -12.50,
            'posted_at' => '2026-08-16',
            'merchant_id' => $context['merchant']->id,
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(PendingSpend::STATUS_RESOLVED, $pending->fresh()->status);
        $this->assertSame($transaction->id, $pending->fresh()->bank_transaction_id);
        $this->assertNull($pending->fresh()->review_reason);
    }

    public function test_create_rejects_order_import_merchants(): void
    {
        $context = $this->context();
        $amazon = Merchant::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order-import merchants are tracked via orders, not pending spend.');

        app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $amazon->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 40.00,
        ]);
    }

    public function test_does_not_claim_allocated_bank_lines(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 40.00,
        ]);

        $allocated = $this->debit($context, [
            'amount' => -40.00,
            'posted_at' => '2026-08-11',
            'merchant_id' => $context['merchant']->id,
        ]);
        $order = Order::factory()->create([
            'user_id' => $context['user']->id,
            'import_batch_id' => $context['batch']->id,
            'merchant_id' => $context['merchant']->id,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'amount' => 40.00,
            'category_id' => null,
        ]);
        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $allocated->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 40.00,
        ]);

        $result = app(PendingSpendMatcher::class)->matchForUser($context['user']->id);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(PendingSpend::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_cancelled_pending_disappears_from_spend(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 18.75,
        ]);

        app(PendingSpendService::class)->cancel($pending);

        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-09-01');

        $this->assertSame(PendingSpend::STATUS_CANCELLED, $pending->fresh()->status);
        $this->assertSame(0.0, app(CategorySpendQuery::class)->uncategorizedExpenseSpendForUser($context['user']->id, $from, $to));
    }

    public function test_pipeline_matches_pending_spend_before_order_reconciliation(): void
    {
        $context = $this->context();
        $pending = app(PendingSpendService::class)->create($context['user'], [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.25,
        ]);
        $transaction = $this->debit($context, [
            'amount' => -12.25,
            'posted_at' => '2026-08-11',
            'merchant_id' => $context['merchant']->id,
        ]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $context['user']->id,
            'status' => 'pending',
        ]);

        (new RunUserReconciliationPipeline($run->id))->handle(
            app(CreditCardPaymentPairingService::class),
            app(TransferPairingService::class),
            app(TransactionCategorizationService::class),
            app(ProductMatchingService::class),
            app(OrderComponentGenerator::class),
            app(MerchantMatcher::class),
            app(PlannedOccurrenceMatcher::class),
            app(OrderPaymentResolutionService::class),
            app(ReconciliationService::class),
            app(VenmoActivityMatcher::class),
            app(PendingSpendMatcher::class),
        );

        $this->assertSame(1, $run->fresh()->metadata['pending_spends_matched']);
        $this->assertSame(PendingSpend::STATUS_RESOLVED, $pending->fresh()->status);
        $this->assertSame($transaction->id, $pending->fresh()->bank_transaction_id);
        $this->assertSame('ignored', $transaction->fresh()->status);
    }

    /**
     * @return array{user: User, account: Account, merchant: Merchant, batch: ImportBatch}
     */
    protected function context(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => "Buc-ee's",
            'normalized_name' => 'buc ee',
            'supports_order_import' => false,
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
        ]);

        return compact('user', 'account', 'merchant', 'batch');
    }

    /**
     * @param  array{user: User, account: Account, merchant: Merchant, batch: ImportBatch}  $context
     * @param  array<string, mixed>  $overrides
     */
    protected function debit(array $context, array $overrides): BankTransaction
    {
        return BankTransaction::factory()->create(array_merge([
            'user_id' => $context['user']->id,
            'account_id' => $context['account']->id,
            'import_batch_id' => $context['batch']->id,
            'card_last_four' => $context['account']->last_four,
            'posted_at' => '2026-08-11',
            'transaction_date' => '2026-08-11',
            'status' => 'unmatched',
            'classification' => null,
            'category_id' => null,
        ], $overrides));
    }
}
