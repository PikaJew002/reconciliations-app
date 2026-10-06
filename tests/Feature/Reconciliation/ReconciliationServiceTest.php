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
use App\Services\Reconciliation\OrderPaymentResolutionService;
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

    public function test_reconciles_apple_pay_last_four_when_it_is_an_alias_of_the_account_card(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '1234',
        ]);
        $account->cardAliases()->create(['last_four' => '9876']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 71.98,
            'payment_last_four' => '9876',
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
            'card_last_four' => '1234',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(1, $matched);
        $this->assertSame('matched', $transaction->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
    }

    public function test_apple_pay_alias_does_not_match_a_different_card_on_the_same_account(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '1234',
        ]);
        $account->cardAliases()->create(['last_four' => '9876']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 71.98,
            'payment_last_four' => '9876',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 71.98,
            'category_id' => null,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-26',
            'transaction_date' => '2026-07-25',
            'amount' => -5.00,
            'card_last_four' => '1234',
            'status' => 'unmatched',
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-27',
            'transaction_date' => '2026-07-25',
            'amount' => -71.98,
            'card_last_four' => '5678',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $transaction->fresh()->status);
    }

    public function test_alias_matches_when_the_account_last_four_is_not_the_number_on_charges(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '6218',
        ]);
        $account->cardAliases()->create(['last_four' => '8517']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-09-28',
            'total' => 81.25,
            'payment_last_four' => '8517',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 81.25,
            'category_id' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-09-28',
            'transaction_date' => '2026-09-28',
            'amount' => -81.25,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(1, $matched);
        $this->assertSame('matched', $transaction->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
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

    public function test_reconciles_unique_multi_transaction_when_order_is_before_bank_coverage(): void
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
            'ordered_at' => '2026-06-28',
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

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $first->fresh()->status);
        $this->assertSame('matched', $second->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertEqualsWithDelta(50.00, (float) $order->fresh()->allocated_amount, 0.01);
    }

    public function test_skips_multi_match_when_order_is_after_bank_coverage(): void
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
            'ordered_at' => '2026-08-16',
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
            'posted_at' => '2026-08-14',
            'amount' => -30.00,
            'card_last_four' => '2195',
            'status' => 'unmatched',
        ]);

        $second = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-15',
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

    public function test_unique_exact_charge_matches_outside_the_date_window_including_the_last_bank_day(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '1234',
        ]);
        $account->cardAliases()->create(['last_four' => '9876']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->createRangeAnchorTransactions($user, $account, $batch, '2026-07-01', '2026-09-25');

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-09-05',
            'total' => 51.81,
            'payment_last_four' => '9876',
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Ending in 9876',
                        'last_four' => '9876',
                        'amount' => 51.81,
                        'kind' => 'gift_card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 51.81,
            'category_id' => null,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-09-25',
            'transaction_date' => '2026-09-25',
            'amount' => -51.81,
            'card_last_four' => '1234',
            'status' => 'unmatched',
        ]);

        $resolved = app(OrderPaymentResolutionService::class)
            ->autoResolveNonBankOnlyOrders($user->id);
        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $resolved);
        $this->assertSame(1, $matched);
        $this->assertSame('matched', $transaction->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertFalse($transaction->fresh()->account->isOffBook());
    }

    public function test_duplicate_exact_amounts_still_require_the_date_window(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '2195',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-08-29',
            'total' => 13.97,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 13.97,
            'category_id' => null,
        ]);

        foreach (['2026-06-16', '2026-09-09'] as $postedAt) {
            BankTransaction::factory()->create([
                'user_id' => $user->id,
                'import_batch_id' => $batch->id,
                'account_id' => $account->id,
                'merchant_id' => $merchant->id,
                'posted_at' => $postedAt,
                'amount' => -13.97,
                'card_last_four' => '2195',
                'status' => 'unmatched',
            ]);
        }

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('imported', $order->fresh()->status);
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

    public function test_bare_ending_in_split_across_charges_matches_the_card_instead_of_a_gift_card(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '5394',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-09-03',
            'total' => 262.63,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Ending in 5394',
                        'last_four' => '5394',
                        'amount' => 262.63,
                        'kind' => 'gift_card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 262.63,
            'category_id' => null,
        ]);

        $reconciled = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-08-01',
            'total' => 10,
            'status' => 'reconciled',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Ending in 5394',
                        'last_four' => '5394',
                        'amount' => 10,
                        'kind' => 'gift_card',
                    ],
                ],
            ],
        ]);

        $charges = collect([8.86, 238.64, 4.51, 10.62])->map(
            fn (float $amount) => BankTransaction::factory()->create([
                'user_id' => $user->id,
                'import_batch_id' => $batch->id,
                'account_id' => $account->id,
                'merchant_id' => $merchant->id,
                'posted_at' => '2026-09-07',
                'transaction_date' => '2026-09-05',
                'description' => 'WALMART.COM',
                'amount' => -$amount,
                'card_last_four' => '5394',
                'status' => 'unmatched',
            ]),
        );

        $resolved = app(OrderPaymentResolutionService::class)
            ->autoResolveNonBankOnlyOrders($user->id);
        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $order->refresh();
        $reconciled->refresh();

        $this->assertSame(0, $resolved);
        $this->assertSame(4, $matched);
        $this->assertSame('reconciled', $order->status);
        $this->assertSame('5394', $order->payment_last_four);
        $this->assertSame('card', $order->metadata['payments'][0]['kind']);
        $this->assertSame('gift_card', $reconciled->metadata['payments'][0]['kind']);
        $this->assertSame('reconciled', $reconciled->status);

        foreach ($charges as $charge) {
            $this->assertSame('matched', $charge->fresh()->status);
            $this->assertFalse($charge->fresh()->account->isOffBook());
        }
    }
}
