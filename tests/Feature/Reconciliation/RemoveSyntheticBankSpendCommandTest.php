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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveSyntheticBankSpendCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_removes_synthetic_orders_and_resets_transactions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'amount' => -25.5,
            'status' => 'matched',
        ]);

        $synthetic = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'status' => 'reconciled',
            'metadata' => [
                'source' => 'bank_synthetic',
                'bank_transaction_id' => $transaction->id,
            ],
        ]);

        $component = OrderComponent::factory()->create([
            'order_id' => $synthetic->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 25.5,
            'category_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 25.5,
        ]);

        $realOrder = Order::factory()->create([
            'user_id' => $other->id,
            'metadata' => ['source' => 'import'],
        ]);

        $this->artisan('reconcile:remove-synthetic-bank-spend')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1 synthetic order(s).')
            ->expectsOutputToContain('Reset 1 bank transaction(s) to unmatched.');

        $this->assertDatabaseMissing('orders', ['id' => $synthetic->id]);
        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
        $this->assertDatabaseMissing('transaction_allocations', [
            'bank_transaction_id' => $transaction->id,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id]);
        $this->assertSame('unmatched', $transaction->fresh()->status);
    }
}
