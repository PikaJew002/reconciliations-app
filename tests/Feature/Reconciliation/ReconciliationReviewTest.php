<?php

namespace Tests\Feature\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReconciliationReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_reconciliation_review(): void
    {
        $this->get(route('reconciliation.index'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_summary_unmatched_orders_and_matched_pairs(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $unmatchedOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'order_number' => 'UNMATCHED-1',
            'total' => 40.00,
            'payment_last_four' => '1234',
            'status' => 'imported',
        ]);

        $reconciledOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'order_number' => 'MATCHED-1',
            'total' => 71.98,
            'payment_last_four' => '2195',
            'status' => 'reconciled',
        ]);

        $component = OrderComponent::factory()->create([
            'order_id' => $reconciledOrder->id,
            'amount' => 71.98,
            'order_item_id' => null,
        ]);

        $matchedTransaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'description' => 'WM SUPERCENTER',
            'amount' => -71.98,
            'card_last_four' => '2195',
            'status' => 'matched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'description' => 'Unmatched purchase',
            'amount' => -12.00,
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'description' => 'Partial purchase',
            'amount' => -50.00,
            'status' => 'partial',
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $matchedTransaction->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 71.98,
            'allocation_type' => 'automatic',
        ]);

        Order::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/Index')
                ->where('summary.unmatched_orders', 1)
                ->where('summary.reconciled_orders', 1)
                ->where('summary.unmatched_transactions', 1)
                ->where('summary.partial_transactions', 1)
                ->where('summary.matched_pairs', 1)
                ->has('unmatchedOrders', 1)
                ->where('unmatchedOrders.0.id', $unmatchedOrder->id)
                ->where('unmatchedOrders.0.order_number', 'UNMATCHED-1')
                ->has('matchedPairs', 1)
                ->where('matchedPairs.0.transaction.id', $matchedTransaction->id)
                ->where('matchedPairs.0.order.id', $reconciledOrder->id)
                ->where('matchedPairs.0.allocated_amount', 71.98)
            );
    }
}
