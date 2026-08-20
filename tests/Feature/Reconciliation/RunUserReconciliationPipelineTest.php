<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\RunUserReconciliationPipeline;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\Product;
use App\Models\ReconciliationRun;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Plans\PlannedOccurrenceMatcher;
use App\Services\Reconciliation\CreditCardPaymentPairingService;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use App\Services\Reconciliation\PendingSpendMatcher;
use App\Services\Reconciliation\ProductMatchingService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\TransactionCategorizationService;
use App\Services\Reconciliation\TransferPairingService;
use App\Services\Reconciliation\VenmoActivityMatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunUserReconciliationPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_marks_run_completed_and_applies_categorization_rules(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => "Buc-ee's",
            'normalized_name' => 'buc ee',
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'merchant_id' => $merchant->id,
            'normalized_pattern' => null,
            'amount' => null,
            'is_active' => true,
        ]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -12.25,
            'posted_at' => '2026-07-22',
            'card_last_four' => '2525',
            'description' => 'BUC-EE S #0055',
            'normalized_description' => 'buc-ee s #0055',
            'status' => 'unmatched',
        ]);

        $this->runPipeline($run);

        $run->refresh();
        $transaction = BankTransaction::query()->first();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['transactions_categorized']);
        $this->assertArrayNotHasKey('synthetic_matched', $run->metadata ?? []);
        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $transaction->classification);
        $this->assertSame($category->id, $transaction->category_id);
    }

    public function test_pipeline_matches_products_before_generating_components(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => 'Milk',
            'normalized_name' => 'milk',
            'sku' => '777',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'subtotal' => 5.00,
            'tax' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
            'total' => 5.00,
            'status' => 'imported',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'sku' => '777',
            'description' => 'Milk',
            'normalized_description' => 'milk',
            'extended_price' => 5.00,
        ]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->runPipeline($run);

        $run->refresh();
        $item->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->metadata['products_created']);
        $this->assertSame(1, $run->metadata['products_linked']);
        $this->assertSame($product->id, $item->product_id);

        $component = OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('type', 'product')
            ->first();

        $this->assertNotNull($component);
        $this->assertSame($category->id, $component->category_id);
    }

    public function test_pipeline_links_planned_occurrences_to_matching_transactions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00'));

        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);
        $template = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'expected_day' => 15,
            'lookback_days' => 7,
            'lookforward_days' => 3,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -140.0,
            'classification' => null,
            'category_id' => null,
            'posted_at' => '2026-08-16',
            'description' => 'DUKE ENERGY 8821',
            'normalized_description' => 'duke energy 8821',
            'status' => 'unmatched',
        ]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        try {
            $this->runPipeline($run);

            $run->refresh();
            $transaction->refresh();

            $this->assertSame('completed', $run->status);
            $this->assertSame(1, $run->metadata['planned_occurrences_matched']);
            $this->assertDatabaseHas('planned_occurrences', [
                'template_id' => $template->id,
                'status' => PlannedOccurrence::STATUS_RESOLVED,
                'bank_transaction_id' => $transaction->id,
            ]);
            $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $transaction->classification);
            $this->assertSame($utilities->id, $transaction->category_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    protected function runPipeline(ReconciliationRun $run): void
    {
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
    }
}
