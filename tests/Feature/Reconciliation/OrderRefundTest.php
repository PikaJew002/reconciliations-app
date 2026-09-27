<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reporting\CategorySpendQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_refund_can_exceed_the_component_and_offsets_tax_without_editing_it(): void
    {
        [$user, $order, $item, $tax] = $this->refundableOrder();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 21.60,
                'refund_kind' => 'bank',
            ])
            ->assertRedirect();

        $item->refresh();
        $tax->refresh();
        $order->refresh();

        $this->assertSame('20.00', $item->amount);
        $this->assertSame('21.60', $item->refund_amount);
        $this->assertSame('bank', $item->refund_kind);
        $this->assertNull($tax->refund_amount);
        $this->assertSame('1.60', $tax->amount);
        $this->assertSame('80.00', $order->total);
        $this->assertSame('80.00', $order->imported_total);
        $this->assertEqualsWithDelta(80.0, $order->payableComponentSum(), 0.01);
    }

    public function test_partial_refund_leaves_the_remainder_in_the_payable_sum(): void
    {
        [$user, $order, $item] = $this->refundableOrder();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 5,
                'refund_kind' => 'bank',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertEqualsWithDelta(96.6, $order->payableComponentSum(), 0.01);
    }

    public function test_off_book_refund_does_not_reduce_the_payable_sum(): void
    {
        [$user, $order, $item] = $this->refundableOrder();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 21.60,
                'refund_kind' => 'off_book',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertEqualsWithDelta(101.6, $order->payableComponentSum(), 0.01);
        $this->assertSame('80.00', $order->total);
        $this->assertSame('80.00', $order->imported_total);
    }

    public function test_bank_total_edit_leaves_the_imported_total_unchanged(): void
    {
        [$user, $order] = $this->refundableOrder();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.total.update', $order), [
                'total' => 101.60,
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('101.60', $order->total);
        $this->assertSame('80.00', $order->imported_total);
    }

    public function test_refund_can_be_cleared(): void
    {
        [$user, $order, $item] = $this->refundableOrder();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'bank',
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.refund.destroy', [$order, $item]))
            ->assertRedirect();

        $item->refresh();
        $order->refresh();

        $this->assertNull($item->refund_amount);
        $this->assertNull($item->refund_kind);
        $this->assertEqualsWithDelta(101.6, $order->payableComponentSum(), 0.01);
    }

    public function test_refund_amount_must_be_positive_and_allocated_components_are_locked(): void
    {
        [$user, $order, $item] = $this->refundableOrder();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 0,
                'refund_kind' => 'bank',
            ])
            ->assertSessionHasErrors('refund_amount');

        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create();
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $order->merchant_id,
            'amount' => -20,
            'status' => 'unmatched',
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $item->id,
            'allocated_amount' => 20,
            'allocation_type' => 'automatic',
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 20,
                'refund_kind' => 'bank',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.total.update', $order), [
                'total' => 90,
            ])
            ->assertStatus(422);
    }

    public function test_spend_drops_by_the_full_refund_including_tax_above_the_component(): void
    {
        [$user, $order, $item, $tax, $category] = $this->refundableOrder();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'bank',
            'category_id' => $category->id,
        ]);

        $query = app(CategorySpendQuery::class);

        $this->assertEqualsWithDelta(
            -1.60,
            $query->orderComponentCategoryTotalsForUser($user->id)[$category->id],
            0.01,
        );
        $this->assertEqualsWithDelta(
            81.60,
            $query->orderComponentUncategorizedSpendForUser($user->id),
            0.01,
        );
        $this->assertSame('1.60', $tax->amount);
        $this->assertSame($order->id, $item->order_id);
    }

    public function test_reconciliation_matches_a_gross_debit_and_a_later_refund_credit(): void
    {
        [$user, $order, $item, $tax, , $merchant, $batch] = $this->refundableOrder();
        $account = Account::factory()->create();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'bank',
        ]);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -101.60, '2026-07-27');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-08-14');
        $netDecoy = $this->bankTransaction($user, $account, $batch, $merchant, -80, '2026-07-26');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $debit->fresh()->status);
        $this->assertSame('matched', $credit->fresh()->status);
        $this->assertSame('unmatched', $netDecoy->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertSame('1.60', $tax->fresh()->amount);
        $this->assertSame('20.00', $item->fresh()->amount);

        $refundAllocation = TransactionAllocation::query()
            ->where('bank_transaction_id', $credit->id)
            ->first();

        $this->assertNotNull($refundAllocation);
        $this->assertSame('refund', $refundAllocation->allocation_type);
        $this->assertEqualsWithDelta(21.60, (float) $refundAllocation->allocated_amount, 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $order->fresh()->allocated_amount, 0.01);
    }

    public function test_bank_refund_does_not_match_a_debit_equal_to_the_net_total_alone(): void
    {
        [$user, $order, $item, , , $merchant, $batch] = $this->refundableOrder();
        $account = Account::factory()->create();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'bank',
        ]);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -80, '2026-07-27');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $debit->fresh()->status);
        $this->assertSame('imported', $order->fresh()->status);
    }

    public function test_store_credit_refund_matches_the_raised_bank_total_and_leaves_credits_alone(): void
    {
        [$user, $order, $item, , $category, $merchant, $batch] = $this->refundableOrder();
        $account = Account::factory()->create();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'off_book',
            'category_id' => $category->id,
        ]);
        $order->update(['total' => 101.60]);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -101.60, '2026-07-27');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-08-14');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(1, $matched);
        $this->assertSame('matched', $debit->fresh()->status);
        $this->assertSame('unmatched', $credit->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertSame('80.00', $order->fresh()->imported_total);
        $this->assertEqualsWithDelta(
            -1.60,
            app(CategorySpendQuery::class)->orderComponentCategoryTotalsForUser($user->id)[$category->id],
            0.01,
        );
    }

    public function test_detail_page_exposes_refund_and_bank_total_fields(): void
    {
        [$user, $order, $item] = $this->refundableOrder();

        $item->update([
            'refund_amount' => 21.60,
            'refund_kind' => 'off_book',
        ]);

        $this->actingAs($user)
            ->get(route('orders.detail', ['merchant' => 'walmart', 'order' => $order]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.imported_total', 80)
                ->where('order.total', 80)
                ->where('order.can_edit_total', true)
                ->where('order.components_balanced', false)
                ->where('components', function ($components) use ($item): bool {
                    $refunded = collect($components)->firstWhere('id', $item->id);

                    return $refunded !== null
                        && $refunded['refund_kind'] === 'off_book'
                        && abs($refunded['refund_amount'] - 21.60) < 0.01
                        && $refunded['can_refund'] === true;
                }));
    }

    /**
     * @return array{0: User, 1: Order, 2: OrderComponent, 3: OrderComponent, 4: Category, 5: Merchant, 6: ImportBatch}
     */
    protected function refundableOrder(): array
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->for($user)->expense()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-25',
            'total' => 80,
            'tax' => 1.60,
            'subtotal' => 100,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
            'payment_last_four' => '2195',
            'status' => 'imported',
        ]);

        $kept = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Kept item',
            'amount' => 80,
            'category_id' => null,
        ]);

        $item = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Returned item',
            'amount' => 20,
            'category_id' => null,
        ]);

        $tax = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 1.60,
            'category_id' => null,
        ]);

        $this->assertSame('80.00', $order->fresh()->imported_total);
        $this->assertNotNull($kept->id);

        return [$user, $order, $item, $tax, $category, $merchant, $batch];
    }

    protected function bankTransaction(
        User $user,
        Account $account,
        ImportBatch $batch,
        Merchant $merchant,
        float $amount,
        string $postedAt,
    ): BankTransaction {
        return BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => $postedAt,
            'transaction_date' => $postedAt,
            'amount' => $amount,
            'card_last_four' => '2195',
            'status' => 'unmatched',
            'classification' => null,
        ]);
    }
}
