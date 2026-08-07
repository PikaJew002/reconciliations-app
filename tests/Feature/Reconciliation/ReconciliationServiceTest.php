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
            'expense_category_id' => null,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 0.21,
            'expense_category_id' => null,
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

        $service = app(ReconciliationService::class);

        $this->assertTrue($service->reconcileTransaction($transaction, $user->id));

        $transaction->refresh();
        $order->refresh();

        $this->assertSame('matched', $transaction->status);
        $this->assertSame('reconciled', $order->status);
        $this->assertCount(2, TransactionAllocation::query()->where('bank_transaction_id', $transaction->id)->get());
        $this->assertEqualsWithDelta(71.98, (float) $transaction->allocated_amount, 0.01);
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
            'expense_category_id' => null,
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

        $service = app(ReconciliationService::class);

        $this->assertFalse($service->reconcileTransaction($transaction, $user->id));
        $this->assertSame('unmatched', $transaction->fresh()->status);
        $this->assertCount(0, TransactionAllocation::all());
    }

    public function test_supports_partial_transaction_allocation(): void
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
            'expense_category_id' => null,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Other items',
            'amount' => 217.77,
            'expense_category_id' => null,
        ]);

        $firstTransaction = BankTransaction::factory()->create([
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

        $service = app(ReconciliationService::class);

        $this->assertTrue($service->reconcileTransaction($firstTransaction, $user->id));

        $firstTransaction->refresh();
        $order->refresh();

        $this->assertSame('matched', $firstTransaction->status);
        $this->assertSame('imported', $order->status);
        $this->assertEqualsWithDelta(31.94, (float) $firstTransaction->allocated_amount, 0.01);
    }
}
