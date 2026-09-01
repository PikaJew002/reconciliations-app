<?php

namespace Tests\Feature\Review;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Review\ReviewSlideBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewSlideBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-30 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_is_included_and_resolved_pending_is_not(): void
    {
        [$user, $account, $batch, $dining] = $this->setupUser();
        $from = Carbon::parse('2026-08-23');
        $to = Carbon::parse('2026-08-30');

        PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 12.5,
            'spent_at' => '2026-08-26 18:00:00',
            'notes' => 'Coffee',
            'status' => PendingSpend::STATUS_PENDING,
        ]);

        $posted = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -18.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-25',
            'description' => 'Posted dinner',
        ]);
        PendingSpend::factory()->resolved($posted)->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 18.0,
            'spent_at' => '2026-08-25 19:00:00',
            'notes' => 'Already posted',
        ]);

        $deck = app(ReviewSlideBuilder::class)->build($user->id, $from, $to);

        $this->assertSame(2, count($deck['slides']));
        $this->assertSame(['bank', 'pending'], array_column($deck['slides'], 'kind'));
        $this->assertSame('Coffee', $deck['slides'][1]['name']);
        $this->assertSame('Not posted', $deck['slides'][1]['badge']);
        $this->assertFalse($deck['slides'][1]['posted']);
        $this->assertSame(30.5, $deck['week_spend']);
    }

    public function test_allocated_bank_line_is_dropped_when_order_components_exist(): void
    {
        [$user, $account, $batch, $dining] = $this->setupUser();
        $from = Carbon::parse('2026-08-23');
        $to = Carbon::parse('2026-08-30');
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'supports_order_import' => true,
        ]);

        $bank = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'amount' => -42.5,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-25',
            'description' => 'AMAZON',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-25',
        ]);
        $paper = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'description' => 'Paper towels',
            'amount' => 20.0,
            'category_id' => $dining->id,
        ]);
        $snacks = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'description' => 'Snacks',
            'amount' => 22.5,
            'category_id' => null,
        ]);
        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $bank->id,
            'order_component_id' => $paper->id,
            'allocated_amount' => 20.0,
        ]);

        $deck = app(ReviewSlideBuilder::class)->build($user->id, $from, $to);
        $kinds = array_column($deck['slides'], 'kind');
        $names = array_column($deck['slides'], 'name');

        $this->assertSame(['order', 'order_component', 'order_component'], $kinds);
        $this->assertSame(['Amazon', 'Paper towels', 'Snacks'], $names);
        $this->assertTrue($deck['slides'][0]['uncategorized']);
        $this->assertSame('order:'.$order->id, $deck['slides'][1]['parent_id']);
        $this->assertSame(42.5, $deck['week_spend']);
        $this->assertSame($snacks->id, $deck['slides'][2]['source_id']);
    }

    public function test_expected_assigned_bills_are_collapsed_and_excluded_from_default_walk(): void
    {
        [$user, $account, $batch, $dining] = $this->setupUser();
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $from = Carbon::parse('2026-08-23');
        $to = Carbon::parse('2026-08-30');

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Paycheck',
        ]);
        $rent = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);
        $paycheck->assignedBills()->sync([$rent->id]);

        $rentTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1200.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $housing->id,
            'posted_at' => '2026-08-24',
            'description' => 'Rent',
        ]);
        PlannedOccurrence::factory()->forTemplate($rent, '2026-08-01')->resolved($rentTx)->create();

        $coffee = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -8.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
            'description' => 'Coffee',
        ]);

        $default = app(ReviewSlideBuilder::class)->build($user->id, $from, $to);
        $all = app(ReviewSlideBuilder::class)->build(
            $user->id,
            $from,
            $to,
            ReviewSlideBuilder::PASS_ALL,
        );

        $this->assertSame(['bank:'.$coffee->id], array_column($default['slides'], 'id'));
        $this->assertSame(8.0, $default['week_spend']);
        $this->assertNotNull($default['expected_bills']);
        $this->assertSame(1200.0, $default['expected_bills']['amount']);
        $this->assertSame('expected_bills', $all['slides'][0]['id']);
        $this->assertSame('bank:'.$coffee->id, $all['slides'][1]['id']);
    }

    public function test_uncategorized_and_needs_review_sort_to_the_front(): void
    {
        [$user, $account, $batch, $dining] = $this->setupUser();
        $from = Carbon::parse('2026-08-23');
        $to = Carbon::parse('2026-08-30');

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-24',
            'description' => 'Categorized early',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -20.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => null,
            'posted_at' => '2026-08-27',
            'description' => 'Mystery charge',
        ]);
        PendingSpend::factory()->needsReview()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 6.0,
            'spent_at' => '2026-08-25 12:00:00',
            'notes' => 'Needs review',
        ]);

        $deck = app(ReviewSlideBuilder::class)->build($user->id, $from, $to);
        $names = array_column($deck['slides'], 'name');

        $this->assertSame(['Needs review', 'Mystery charge', 'Categorized early'], $names);
    }

    /**
     * @return array{0: User, 1: Account, 2: ImportBatch, 3: Category}
     */
    protected function setupUser(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        return [$user, $account, $batch, $dining];
    }
}
