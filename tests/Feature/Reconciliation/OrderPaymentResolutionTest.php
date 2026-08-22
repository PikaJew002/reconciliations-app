<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\User;
use App\Services\Imports\WalmartOrderImporter;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OrderPaymentResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_walmart_payment_kinds_from_json_endings(): void
    {
        $importer = app(WalmartOrderImporter::class);
        $method = new ReflectionMethod(WalmartOrderImporter::class, 'parsePaymentMethods');
        $method->setAccessible(true);

        $payments = $method->invoke($importer, [
            'paymentMethodDetails' => [
                ['ending' => 'Ending in 8723', 'amount' => ''],
                ['ending' => 'Mastercard ending in 2195', 'amount' => ''],
            ],
        ]);

        $this->assertSame('gift_card', $payments[0]['kind']);
        $this->assertSame('8723', $payments[0]['last_four']);
        $this->assertSame('card', $payments[1]['kind']);
        $this->assertSame('2195', $payments[1]['last_four']);

        $balancePayments = $method->invoke($importer, [
            'paymentMethodDetails' => [
                ['ending' => 'Walmart Balance', 'amount' => ''],
                ['ending' => 'Walmart Mastercard ending in 2525', 'amount' => ''],
            ],
        ]);

        $this->assertSame('walmart_balance', $balancePayments[0]['kind']);
        $this->assertSame('card', $balancePayments[1]['kind']);
        $this->assertSame('2525', $balancePayments[1]['last_four']);
    }

    public function test_resolves_multi_payment_order_with_card_and_gift_card(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['account_id' => $account->id],
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'order_number' => '55088052794904592777',
            'ordered_at' => '2026-07-18',
            'total' => 61.04,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Ending in 8723',
                        'last_four' => '8723',
                        'amount' => null,
                        'kind' => 'gift_card',
                    ],
                    [
                        'ending' => 'Mastercard ending in 2195',
                        'last_four' => '2195',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Groceries',
            'amount' => 61.04,
            'order_item_id' => null,
        ]);

        $cardTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-20',
            'transaction_date' => '2026-07-18',
            'amount' => -11.04,
            'card_last_four' => '2195',
            'status' => 'unmatched',
            'description' => 'WM SUPERCENTER',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.orders.resolve-payments', $order), [
                'payments' => [
                    ['index' => 0, 'amount' => 50.00, 'bank_transaction_id' => null],
                    ['index' => 1, 'amount' => 11.04, 'bank_transaction_id' => $cardTx->id],
                ],
            ])
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('success');

        $order->refresh();
        $cardTx->refresh();

        $this->assertSame('reconciled', $order->status);
        $this->assertSame('2195', $order->payment_last_four);
        $this->assertSame('matched', $cardTx->status);
        $this->assertSame(50.0, (float) $order->metadata['payments'][0]['amount']);
        $this->assertSame(11.04, (float) $order->metadata['payments'][1]['amount']);

        $this->assertDatabaseHas('bank_transactions', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'amount' => -50.00,
            'status' => 'matched',
        ]);

        $giftTx = BankTransaction::query()
            ->where('user_id', $user->id)
            ->where('amount', -50.00)
            ->first();

        $this->assertNotNull($giftTx);
        $this->assertTrue($giftTx->account->isOffBook());
        $this->assertSame($account->id, $cardTx->account_id);
        $this->assertNotSame($account->id, $giftTx->account_id);
    }

    public function test_payment_review_orders_appear_in_reconciliation_index(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'total' => 60.39,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Walmart Balance',
                        'last_four' => null,
                        'amount' => null,
                        'kind' => 'walmart_balance',
                    ],
                    [
                        'ending' => 'Walmart Mastercard ending in 2525',
                        'last_four' => '2525',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 60.39,
            'order_item_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('reconciliation.needs-review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reconciliation/NeedsReview')
                ->where('summary.payment_review_orders', 1)
                ->where('summary.needs_review', 1)
                ->has('paymentReviewOrders', 1)
                ->where('paymentReviewOrders.0.id', $order->id)
                ->where('paymentReviewOrders.0.payments.0.kind', 'walmart_balance')
                ->where('paymentReviewOrders.0.payments.1.kind', 'card')
            );
    }

    public function test_needs_payment_review_detection(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'payment_last_four' => null,
            'metadata' => [
                'payments' => [
                    ['ending' => 'Ending in 8723', 'last_four' => '8723', 'amount' => null],
                    ['ending' => 'Mastercard ending in 2195', 'last_four' => '2195', 'amount' => null],
                ],
            ],
        ]);

        $service = app(OrderPaymentResolutionService::class);

        $this->assertTrue($service->needsPaymentReview($order));
        $payments = $service->normalizedPayments($order);
        $this->assertSame('gift_card', $payments[0]['kind']);
        $this->assertSame('card', $payments[1]['kind']);
    }

    public function test_auto_resolves_gift_only_amazon_order(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'order_number' => '114-8413256-9366657',
            'ordered_at' => '2026-07-21',
            'total' => 15.84,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Amazon gift card balance',
                        'last_four' => null,
                        'amount' => 15.84,
                        'kind' => 'gift_card',
                    ],
                ],
            ],
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Tonies Cinderella',
            'amount' => 14.94,
            'order_item_id' => null,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 0.90,
            'order_item_id' => null,
        ]);

        $resolved = app(OrderPaymentResolutionService::class)
            ->autoResolveNonBankOnlyOrders($user->id);

        $order->refresh();

        $this->assertSame(1, $resolved);
        $this->assertSame('reconciled', $order->status);
        $this->assertDatabaseHas('bank_transactions', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'amount' => -15.84,
            'status' => 'matched',
        ]);
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'external_id' => Account::OFF_BOOK_EXTERNAL_ID,
            'name' => Account::OFF_BOOK_NAME,
        ]);

        $giftTx = BankTransaction::query()
            ->where('user_id', $user->id)
            ->where('amount', -15.84)
            ->first();

        $this->assertNotNull($giftTx);
        $this->assertTrue($giftTx->account->isOffBook());
    }

    public function test_amazon_split_payments_need_review_with_prefilled_amounts(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'total' => 41.37,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Visa ending in 8463',
                        'last_four' => '8463',
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

        $service = app(OrderPaymentResolutionService::class);

        $this->assertTrue($service->needsPaymentReview($order));
        $payments = $service->normalizedPayments($order);
        $this->assertSame(7.21, $payments[0]['amount']);
        $this->assertSame(34.16, $payments[1]['amount']);
        $this->assertSame(0, $service->autoResolveNonBankOnlyOrders($user->id));
        $this->assertSame('imported', $order->fresh()->status);
    }

    public function test_can_mark_branded_card_as_gift_card_when_resolving(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'metadata' => ['account_id' => $account->id],
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'order_number' => '114-0000000-0000000',
            'ordered_at' => '2026-07-21',
            'total' => 41.37,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Visa ending in 8463',
                        'last_four' => '8463',
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
            'type' => 'product',
            'description' => 'Item',
            'amount' => 41.37,
            'order_item_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.orders.resolve-payments', $order), [
                'payments' => [
                    [
                        'index' => 0,
                        'amount' => 7.21,
                        'bank_transaction_id' => null,
                        'kind' => 'gift_card',
                    ],
                    [
                        'index' => 1,
                        'amount' => 34.16,
                        'bank_transaction_id' => null,
                        'kind' => 'gift_card',
                    ],
                ],
            ])
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('reconciled', $order->status);
        $this->assertNull($order->payment_last_four);
        $this->assertSame('gift_card', $order->metadata['payments'][0]['kind']);
        $this->assertSame('Visa ending in 8463', $order->metadata['payments'][0]['ending']);
        $this->assertSame('gift_card', $order->metadata['payments'][1]['kind']);

        $this->assertDatabaseHas('bank_transactions', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'description' => 'Visa ending in 8463',
            'amount' => -7.21,
            'status' => 'matched',
            'card_last_four' => '8463',
        ]);

        $this->assertDatabaseHas('bank_transactions', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'description' => 'Amazon gift card balance',
            'amount' => -34.16,
            'status' => 'matched',
        ]);

        $offBook = Account::query()
            ->where('user_id', $user->id)
            ->offBook()
            ->first();

        $this->assertNotNull($offBook);
        $this->assertDatabaseHas('bank_transactions', [
            'account_id' => $offBook->id,
            'description' => 'Visa ending in 8463',
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'account_id' => $offBook->id,
            'description' => 'Amazon gift card balance',
        ]);
        $this->assertSame(1, Account::query()->where('user_id', $user->id)->offBook()->count());
    }

    public function test_user_can_remove_duplicate_payment_method(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'order_number' => '05031290015277424060',
            'total' => 42.18,
            'payment_last_four' => null,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Walmart Mastercard ending in 2525',
                        'last_four' => '2525',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                    [
                        'ending' => 'Walmart Mastercard ending in 2525',
                        'last_four' => '2525',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.payments.destroy', [$order, 0]))
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertCount(1, $order->metadata['payments']);
        $this->assertSame('2525', $order->payment_last_four);
        $this->assertSame(
            'Walmart Mastercard ending in 2525',
            $order->metadata['payments'][0]['ending'],
        );
    }

    public function test_cannot_remove_only_remaining_payment_method(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
            'payment_last_four' => '2525',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Walmart Mastercard ending in 2525',
                        'last_four' => '2525',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.payments.destroy', [$order, 0]))
            ->assertRedirect(route('reconciliation.needs-review'))
            ->assertSessionHas('error');

        $order->refresh();

        $this->assertCount(1, $order->metadata['payments']);
    }

    public function test_cannot_remove_payment_from_another_users_order(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $other->id]);

        $order = Order::factory()->create([
            'user_id' => $other->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Mastercard ending in 1111',
                        'last_four' => '1111',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                    [
                        'ending' => 'Mastercard ending in 2222',
                        'last_four' => '2222',
                        'amount' => null,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.payments.destroy', [$order, 0]))
            ->assertForbidden();
    }
}
