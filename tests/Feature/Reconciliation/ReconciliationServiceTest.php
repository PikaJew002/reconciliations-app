<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_exact_match_with_card_last_four(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 71.98,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Groceries',
            'amount' => 71.77,
            'category_id' => null,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 0.21,
            'category_id' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-25',
            'amount' => -71.98,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(1, $matched);
        $this->assertSame('matched', $transaction->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertCount(2, TransactionAllocation::query()->where('bank_transaction_id', $transaction->id)->get());
        $this->assertEqualsWithDelta(71.98, (float) $transaction->fresh()->allocated_amount, 0.01);
    }

    public function test_skips_match_when_card_last_four_differs(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 71.98,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 71.98,
            'category_id' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-25',
            'amount' => -71.98,
            'card_last_four' => '2525',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $transaction->fresh()->status);
        $this->assertCount(0, TransactionAllocation::all());
    }

    public function test_does_not_partially_allocate_a_smaller_transaction(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->createRangeAnchorTransactions($user, $account, $batch, '2026-07-01', '2026-08-15');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-20',
            'total' => 249.71,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Milk',
            'amount' => 31.94,
            'category_id' => null,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Other items',
            'amount' => 217.77,
            'category_id' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-21',
            'transaction_date' => '2026-07-20',
            'amount' => -31.94,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $transaction->fresh()->status);
        $this->assertSame('imported', $order->fresh()->status);
        $this->assertCount(0, TransactionAllocation::all());
    }

    public function test_exact_one_to_one_wins_over_smaller_same_posted_at_transactions(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 71.98,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 71.98,
            'category_id' => null,
        ]);

        $smallOne = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-26',
            'amount' => -3.74,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $smallTwo = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-26',
            'amount' => -21.18,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $exact = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-25',
            'amount' => -71.98,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(1, $matched);
        $this->assertSame('matched', $exact->fresh()->status);
        $this->assertSame('unmatched', $smallOne->fresh()->status);
        $this->assertSame('unmatched', $smallTwo->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertEqualsWithDelta(71.98, (float) $exact->fresh()->allocated_amount, 0.01);
    }

    public function test_reconciles_unique_multi_transaction_exact_sum(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->createRangeAnchorTransactions($user, $account, $batch, '2026-07-01', '2026-08-15');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-20',
            'total' => 50.00,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 50.00,
            'category_id' => null,
        ]);

        $first = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-21',
            'amount' => -30.00,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $second = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-22',
            'amount' => -20.00,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $first->fresh()->status);
        $this->assertSame('matched', $second->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertEqualsWithDelta(50.00, (float) $order->fresh()->allocated_amount, 0.01);
    }

    public function test_skips_multi_match_when_order_is_near_import_edge(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        // Range anchors only — order on 2026-07-03 is within 3 days of min posted_at.
        $this->createRangeAnchorTransactions($user, $account, $batch, '2026-07-01', '2026-08-15');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-03',
            'total' => 50.00,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 50.00,
            'category_id' => null,
        ]);

        $first = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-04',
            'amount' => -30.00,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $second = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-05',
            'amount' => -20.00,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $first->fresh()->status);
        $this->assertSame('unmatched', $second->fresh()->status);
        $this->assertSame('imported', $order->fresh()->status);
    }

    public function test_skips_ambiguous_multi_transaction_subsets(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->createRangeAnchorTransactions($user, $account, $batch, '2026-07-01', '2026-08-15');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-20',
            'total' => 50.00,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 50.00,
            'category_id' => null,
        ]);

        // Two distinct subsets sum to 50: {30,20} and {40,10}.
        foreach ([-30.00, -20.00, -40.00, -10.00] as $amount) {
            BankTransaction::factory()->create([
                'user_id' => $user->id,
                'import_batch_id' => $batch->id,
                'account_id' => $account->id,
                'merchant_id' => $merchant->id,
                'posted_at' => '2026-07-21',
                'amount' => $amount,
                'card_last_four' => '2195',
                'status' => 'unmatched',
            ]);
        }

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('imported', $order->fresh()->status);
        $this->assertCount(0, TransactionAllocation::all());
    }

    protected function createRangeAnchorTransactions(
        User $user,
        Account $account,
        ImportBatch $batch,
        string $minPostedAt,
        string $maxPostedAt,
    ): void {
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'posted_at' => $minPostedAt,
            'amount' => -1.00,
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'posted_at' => $maxPostedAt,
            'amount' => -1.00,
            'status' => 'unmatched',
        ]);
    }
}
