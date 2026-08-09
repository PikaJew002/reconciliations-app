<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\RunUserReconciliationPipeline;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\ReconciliationRun;
use App\Models\TransactionAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        OrderComponent::factory()->create([
            'order_id' => $unmatchedOrder->id,
            'amount' => 40.00,
            'order_item_id' => null,
        ]);

        $unbalancedOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'order_number' => 'UNBALANCED-1',
            'total' => 199.33,
            'payment_last_four' => '2525',
            'status' => 'imported',
        ]);

        $unbalancedItem = OrderItem::factory()->create([
            'order_id' => $unbalancedOrder->id,
            'description' => 'Groceries',
            'quantity' => 2,
            'unit_price' => 97.16,
            'extended_price' => 194.33,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $unbalancedOrder->id,
            'order_item_id' => $unbalancedItem->id,
            'type' => 'product',
            'description' => 'Groceries',
            'amount' => 194.33,
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

        $unmatchedTransaction = BankTransaction::factory()->create([
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
                ->where('summary.unmatched_orders', 2)
                ->where('summary.reconciled_orders', 1)
                ->where('summary.unmatched_transactions', 1)
                ->where('summary.partial_transactions', 1)
                ->where('summary.matched_pairs', 1)
                ->where('summary.unbalanced_orders', 1)
                ->where('summary.payment_review_orders', 0)
                ->where('summary.needs_review', 1)
                ->where('activeRun', null)
                ->has('unmatchedOrders', 2)
                ->has('unbalancedOrders', 1)
                ->has('paymentReviewOrders', 0)
                ->where('unbalancedOrders.0.id', $unbalancedOrder->id)
                ->where('unbalancedOrders.0.gap', 5)
                ->where('unbalancedOrders.0.components.0.order_item_id', $unbalancedItem->id)
                ->where('unbalancedOrders.0.components.0.quantity', 2)
                ->where('unbalancedOrders.0.components.0.can_edit_quantity', true)
                ->has('unmatchedTransactions', 1)
                ->where('unmatchedTransactions.0.id', $unmatchedTransaction->id)
                ->where('unmatchedTransactions.0.description', 'Unmatched purchase')
                ->has('matchedPairs', 1)
                ->where('matchedPairs.0.transaction.id', $matchedTransaction->id)
                ->where('matchedPairs.0.order.id', $reconciledOrder->id)
                ->where('matchedPairs.0.allocated_amount', 71.98)
            );
    }

    public function test_guests_cannot_run_reconciliation(): void
    {
        $this->post(route('reconciliation.run'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_queue_reconciliation_for_existing_data(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reconciliation.run'))
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $run = ReconciliationRun::query()->where('user_id', $user->id)->sole();

        $this->assertSame('pending', $run->status);

        Queue::assertPushed(RunUserReconciliationPipeline::class, function (RunUserReconciliationPipeline $job) use ($run) {
            return $job->reconciliationRunId === $run->id;
        });
    }

    public function test_index_includes_active_run_while_processing(): void
    {
        $user = User::factory()->create();

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reconciliation/Index')
                ->where('activeRun.id', $run->id)
                ->where('activeRun.status', 'processing')
            );
    }

    public function test_does_not_queue_second_run_while_one_is_in_progress(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.run'))
            ->assertRedirect(route('reconciliation.index'));

        $this->assertSame(1, ReconciliationRun::query()->where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }
}
