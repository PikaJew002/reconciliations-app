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
use App\Services\Reconciliation\OrderPaymentResolutionService;
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

    public function test_reconciled_amazon_card_refund_reopens_and_matches_the_later_credit(): void
    {
        [$user, $order, $item, $tax, $category, $merchant, $batch, $account, $debit] = $this->reconciledAmazonCharge();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 21.60,
                'refund_kind' => 'bank',
            ])
            ->assertRedirect();

        $order->refresh();
        $item->refresh();
        $tax->refresh();
        $debit->refresh();

        $this->assertSame('imported', $order->status);
        $this->assertSame('unmatched', $debit->status);
        $this->assertSame(0, TransactionAllocation::query()->where('bank_transaction_id', $debit->id)->count());
        $this->assertSame('21.60', $item->refund_amount);
        $this->assertSame('bank', $item->refund_kind);
        $this->assertSame('1.60', $tax->amount);
        $this->assertNull($tax->refund_amount);
        $this->assertEqualsWithDelta(0.0, $order->payableComponentSum(), 0.01);
        $this->assertSame('21.60', $order->imported_total);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.total.update', $order), [
                'total' => 0,
            ])
            ->assertRedirect();

        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-07-20', '1111');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $debit->fresh()->status);
        $this->assertSame('matched', $credit->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertSame('refund', TransactionAllocation::query()->where('bank_transaction_id', $credit->id)->value('allocation_type'));
        $this->assertEqualsWithDelta(
            -1.60,
            app(CategorySpendQuery::class)->orderComponentCategoryTotalsForUser($user->id)[$category->id],
            0.01,
        );
    }

    public function test_bank_total_edit_reopens_a_reconciled_amazon_order(): void
    {
        [$user, $order, , , , , , , $debit] = $this->reconciledAmazonCharge();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.total.update', $order), [
                'total' => 0,
            ])
            ->assertRedirect();

        $this->assertSame('imported', $order->fresh()->status);
        $this->assertSame('0.00', $order->fresh()->total);
        $this->assertSame('21.60', $order->fresh()->imported_total);
        $this->assertSame('unmatched', $debit->fresh()->status);
        $this->assertSame(0, TransactionAllocation::query()->where('bank_transaction_id', $debit->id)->count());
    }

    public function test_amazon_split_tender_refund_matches_the_card_without_a_gift_card_charge(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-01',
            'total' => 34.16,
            'imported_total' => 41.37,
            'subtotal' => 41.37,
            'tax' => 0,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Visa ending in 4444',
                        'last_four' => '4444',
                        'amount' => 7.21,
                        'kind' => 'card',
                    ],
                    [
                        'ending' => 'Amazon gift card balance',
                        'last_four' => null,
                        'amount' => 34.16,
                        'kind' => 'gift_card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Gift card item',
            'amount' => 34.16,
        ]);

        $cardItem = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Card item',
            'amount' => 7.21,
            'refund_amount' => 7.21,
            'refund_kind' => 'bank',
        ]);

        $resolution = app(OrderPaymentResolutionService::class);

        $this->assertTrue($resolution->needsPaymentReview($order));
        $this->assertFalse($resolution->blocksRefundMatching($order));
        $this->assertEqualsWithDelta(34.16, $order->payableComponentSum(), 0.01);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -7.21, '2026-07-02', '4444');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 7.21, '2026-07-20', '4444');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $debit->fresh()->status);
        $this->assertSame('matched', $credit->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertSame('refund', TransactionAllocation::query()->where('bank_transaction_id', $credit->id)->value('allocation_type'));
        $this->assertSame($cardItem->id, (int) TransactionAllocation::query()->where('bank_transaction_id', $credit->id)->value('order_component_id'));

        $gift = BankTransaction::query()
            ->where('user_id', $user->id)
            ->where('amount', -34.16)
            ->first();

        $this->assertNotNull($gift);
        $this->assertSame('non_bank_tender', $gift->metadata['source']);
        $this->assertTrue($gift->account->isOffBook());
        $this->assertNotSame($account->id, $gift->account_id);
    }

    public function test_amazon_refund_credit_after_day_7_still_matches(): void
    {
        [$user, $order, $merchant, $batch, $account] = $this->openAmazonRefund('2026-07-02');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-07-12', '1111');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $credit->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
    }

    public function test_amazon_refund_credit_after_day_30_and_a_late_debit_still_match(): void
    {
        [$user, $order, $merchant, $batch, $account] = $this->openAmazonRefund('2026-07-21');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-08-20', '1111');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(2, $matched);
        $this->assertSame('matched', $credit->fresh()->status);
        $this->assertSame('reconciled', $order->fresh()->status);
    }

    public function test_amazon_refund_credit_outside_90_days_does_not_match(): void
    {
        [$user, $order, $merchant, $batch, $account, $debit] = $this->openAmazonRefund('2026-07-02');
        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-09-30', '1111');

        $matched = app(ReconciliationService::class)->reconcileForUser($user->id);

        $this->assertSame(0, $matched);
        $this->assertSame('unmatched', $debit->fresh()->status);
        $this->assertSame('unmatched', $credit->fresh()->status);
        $this->assertSame('imported', $order->fresh()->status);
    }

    public function test_clearing_a_matched_amazon_refund_unwinds_allocations_and_leaves_the_order_open(): void
    {
        [$user, $order, $item, , , $merchant, $batch, $account, $debit] = $this->reconciledAmazonCharge();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.refund.update', [$order, $item]), [
                'refund_amount' => 21.60,
                'refund_kind' => 'bank',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.total.update', $order), [
                'total' => 0,
            ])
            ->assertRedirect();

        $credit = $this->bankTransaction($user, $account, $batch, $merchant, 21.60, '2026-07-20', '1111');

        $this->assertSame(2, app(ReconciliationService::class)->reconcileForUser($user->id));
        $this->assertSame('reconciled', $order->fresh()->status);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.refund.destroy', [$order, $item]))
            ->assertRedirect();

        $item->refresh();
        $order->refresh();

        $this->assertNull($item->refund_amount);
        $this->assertNull($item->refund_kind);
        $this->assertSame('imported', $order->status);
        $this->assertSame('unmatched', $debit->fresh()->status);
        $this->assertSame('unmatched', $credit->fresh()->status);
        $this->assertSame(0, TransactionAllocation::query()->whereIn('order_component_id', $order->components()->pluck('id'))->count());
        $this->assertEqualsWithDelta(21.60, $order->payableComponentSum(), 0.01);
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
        string $lastFour = '2195',
    ): BankTransaction {
        return BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => $postedAt,
            'transaction_date' => $postedAt,
            'amount' => $amount,
            'card_last_four' => $lastFour,
            'status' => 'unmatched',
            'classification' => null,
        ]);
    }

    /**
     * @return array{0: User, 1: Order, 2: OrderComponent, 3: OrderComponent, 4: Category, 5: Merchant, 6: ImportBatch, 7: Account, 8: BankTransaction}
     */
    protected function reconciledAmazonCharge(): array
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-01',
            'total' => 21.60,
            'tax' => 1.60,
            'subtotal' => 20,
            'payment_last_four' => '1111',
            'status' => 'reconciled',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Mastercard ending in 1111',
                        'last_four' => '1111',
                        'amount' => 21.60,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        $item = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Returned item',
            'amount' => 20,
            'category_id' => $category->id,
        ]);

        $tax = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 1.60,
        ]);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -21.60, '2026-07-02', '1111');
        $debit->update(['status' => 'matched']);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $debit->id,
            'order_component_id' => $item->id,
            'allocated_amount' => 20,
            'allocation_type' => 'automatic',
        ]);
        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $debit->id,
            'order_component_id' => $tax->id,
            'allocated_amount' => 1.60,
            'allocation_type' => 'automatic',
        ]);

        return [$user, $order, $item, $tax, $category, $merchant, $batch, $account, $debit];
    }

    /**
     * @return array{0: User, 1: Order, 2: Merchant, 3: ImportBatch, 4: Account, 5: BankTransaction}
     */
    protected function openAmazonRefund(string $debitPostedAt): array
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->create();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'ordered_at' => '2026-07-01',
            'total' => 0,
            'tax' => 1.60,
            'subtotal' => 20,
            'payment_last_four' => '1111',
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Returned item',
            'amount' => 20,
            'refund_amount' => 21.60,
            'refund_kind' => 'bank',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 1.60,
        ]);

        $debit = $this->bankTransaction($user, $account, $batch, $merchant, -21.60, $debitPostedAt, '1111');

        return [$user, $order, $merchant, $batch, $account, $debit];
    }
}
