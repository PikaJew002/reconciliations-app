<?php

namespace Tests\Feature\Orders;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Imports\AmazonScrapeOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_order_detail(): void
    {
        $this->get('/orders/amazon/1')
            ->assertRedirect('/login');
    }

    public function test_guests_are_redirected_from_order_destroy(): void
    {
        $this->delete('/orders/amazon/1')
            ->assertRedirect('/login');
    }

    public function test_detail_returns_404_for_another_users_order(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $amazon = Merchant::factory()->create([
            'user_id' => $other->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);

        $order = Order::factory()->create([
            'user_id' => $other->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'AMZ-OTHER',
        ]);

        $this->actingAs($user)
            ->get(route('orders.detail', ['merchant' => 'amazon', 'order' => $order->id]))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('orders.destroy', ['merchant' => 'amazon', 'order' => $order->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_detail_returns_404_for_merchant_mismatch(): void
    {
        $user = User::factory()->create();

        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'AMZ-1',
        ]);

        $this->actingAs($user)
            ->get(route('orders.detail', ['merchant' => 'walmart', 'order' => $order->id]))
            ->assertNotFound();

        $this->actingAs($user)
            ->delete(route('orders.destroy', ['merchant' => 'walmart', 'order' => $order->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_amazon_detail_includes_items_components_and_payments(): void
    {
        $user = User::factory()->create();
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $category = Category::factory()->expense()->create([
            'user_id' => $user->id,
            'name' => 'Household',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => '111-0000002-0000002',
            'ordered_at' => '2026-08-07 00:00:00',
            'delivered_at' => null,
            'subtotal' => 6.97,
            'tax' => 0.42,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
            'total' => 7.39,
            'payment_last_four' => '1111',
            'status' => 'imported',
            'metadata' => [
                'payments' => [
                    [
                        'ending' => 'Mastercard ending in 1111',
                        'last_four' => '1111',
                        'amount' => 7.39,
                        'kind' => 'card',
                    ],
                ],
            ],
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'sku' => 'B0B6R34RD4',
            'description' => 'Carabiner',
            'quantity' => 1,
            'unit_price' => 6.97,
            'extended_price' => 6.97,
        ]);

        $productComponent = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => 'Carabiner',
            'amount' => 6.97,
            'category_id' => $category->id,
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => 0.42,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('orders.detail', ['merchant' => 'amazon', 'order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Detail')
                ->where('merchant.normalized_name', 'amazon')
                ->where('merchant.name', 'Amazon')
                ->where('order.id', $order->id)
                ->where('order.order_number', '111-0000002-0000002')
                ->where('order.total', 7.39)
                ->where('order.tax', 0.42)
                ->where('order.payment_last_four', '1111')
                ->where('can_delete', true)
                ->where('has_allocations', false)
                ->has('order.payments', 1)
                ->where('order.payments.0.kind', 'card')
                ->where('order.payments.0.last_four', '1111')
                ->has('items', 1)
                ->where('items.0.id', $item->id)
                ->where('items.0.description', 'Carabiner')
                ->where('items.0.sku', 'B0B6R34RD4')
                ->where('items.0.quantity', 1)
                ->where('items.0.extended_price', 6.97)
                ->has('components', 2)
                ->where('components.0.id', $productComponent->id)
                ->where('components.0.type', 'product')
                ->where('components.0.category.name', 'Household')
                ->where('components.0.allocated_amount', 0)
                ->where('components.1.type', 'tax')
                ->where('components.1.description', 'Sales Tax'));
    }

    public function test_destroy_removes_order_and_unwinds_bank_matches(): void
    {
        $user = User::factory()->create();
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $account = Account::factory()->create(['is_active' => true]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $amazon->id,
            'amount' => -7.39,
            'status' => 'matched',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'AMZ-DELETE',
            'total' => 7.39,
            'status' => 'reconciled',
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Carabiner',
            'quantity' => 1,
            'unit_price' => 7.39,
            'extended_price' => 7.39,
        ]);

        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => 'Carabiner',
            'amount' => 7.39,
            'category_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 7.39,
        ]);

        $this->actingAs($user)
            ->delete(route('orders.destroy', ['merchant' => 'amazon', 'order' => $order->id]))
            ->assertRedirect(route('orders.show', 'amazon'))
            ->assertSessionHas('success', 'Order AMZ-DELETE removed.');

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
        $this->assertDatabaseMissing('transaction_allocations', [
            'bank_transaction_id' => $transaction->id,
        ]);
        $this->assertSame('unmatched', $transaction->fresh()->status);
    }

    public function test_destroy_leaves_bank_transactions_matched_when_other_allocations_remain(): void
    {
        $user = User::factory()->create();
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $account = Account::factory()->create(['is_active' => true]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $amazon->id,
            'amount' => -20.00,
            'status' => 'matched',
        ]);

        $keep = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'KEEP',
            'total' => 10.00,
            'status' => 'imported',
        ]);

        $remove = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'REMOVE',
            'total' => 10.00,
            'status' => 'imported',
        ]);

        $keepComponent = OrderComponent::factory()->create([
            'order_id' => $keep->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
            'category_id' => null,
        ]);

        $removeComponent = OrderComponent::factory()->create([
            'order_id' => $remove->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
            'category_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $keepComponent->id,
            'allocated_amount' => 10.00,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $removeComponent->id,
            'allocated_amount' => 10.00,
        ]);

        $this->actingAs($user)
            ->delete(route('orders.destroy', ['merchant' => 'amazon', 'order' => $remove->id]))
            ->assertRedirect(route('orders.show', 'amazon'));

        $this->assertDatabaseMissing('orders', ['id' => $remove->id]);
        $this->assertDatabaseHas('orders', ['id' => $keep->id]);
        $this->assertSame('matched', $transaction->fresh()->status);
        $this->assertDatabaseHas('transaction_allocations', [
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $keepComponent->id,
        ]);
    }

    public function test_deleting_an_order_allows_the_same_order_number_to_be_reimported(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/amazon-scrape.json';

        Storage::disk('local')->put($path, json_encode($this->scrapePayload()));

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [
                'format' => 'scrape_json',
            ],
        ]);

        $importer = app(AmazonScrapeOrderImporter::class);

        $this->assertSame(1, $importer->import($batch));
        $this->assertSame(0, $importer->import($batch));

        $order = Order::query()->where('order_number', '111-0000002-0000002')->first();
        $this->assertNotNull($order);

        $this->actingAs($user)
            ->delete(route('orders.destroy', ['merchant' => 'amazon', 'order' => $order->id]))
            ->assertRedirect(route('orders.show', 'amazon'));

        $this->assertDatabaseMissing('orders', ['order_number' => '111-0000002-0000002']);
        $this->assertSame(1, $importer->import($batch));
        $this->assertDatabaseHas('orders', [
            'order_number' => '111-0000002-0000002',
            'user_id' => $user->id,
        ]);
        $this->assertSame(1, OrderItem::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    protected function scrapePayload(): array
    {
        return [
            'scrapedAt' => '2026-08-17T03:29:22.995Z',
            'summary' => [
                'page' => 'summary',
                'orderCount' => 1,
                'orders' => [],
            ],
            'details' => [
                [
                    'success' => true,
                    'orderNumber' => '111-0000002-0000002',
                    'data' => [
                        'orderNumber' => '111-0000002-0000002',
                        'orderDate' => 'August 7, 2026',
                        'paymentMethod' => 'Mastercardending in 1111',
                        'summary' => [
                            'items_subtotal' => 6.97,
                            'estimated_tax_to_be_collected' => 0.42,
                            'grand_total' => 7.39,
                        ],
                        'shipments' => [
                            [
                                'status' => 'Delivered August 8',
                                'items' => [
                                    [
                                        'title' => 'Carabiner',
                                        'asin' => 'B0B6R34RD4',
                                        'quantity' => 1,
                                        'unitPrice' => 6.97,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
