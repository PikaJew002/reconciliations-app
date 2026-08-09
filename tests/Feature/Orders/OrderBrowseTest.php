<?php

namespace Tests\Feature\Orders;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_orders_index(): void
    {
        $this->get(route('orders.index'))
            ->assertRedirect('/login');
    }

    public function test_guests_are_redirected_from_orders_show(): void
    {
        $this->get(route('orders.show', 'walmart'))
            ->assertRedirect('/login');
    }

    public function test_index_lists_retailers_and_other_merchants_with_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'type' => Merchant::RETAILER,
            'supports_order_import' => true,
        ]);

        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'type' => Merchant::RETAILER,
            'supports_order_import' => true,
        ]);

        $target = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Target',
            'normalized_name' => 'target',
            'type' => Merchant::RETAILER,
            'supports_order_import' => false,
        ]);

        $otherWalmart = Merchant::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);

        $account = Account::factory()->create(['is_active' => true]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $target->id,
            'posted_at' => '2026-06-01',
            'amount' => -10.00,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $target->id,
            'posted_at' => '2026-08-06',
            'amount' => -20.00,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $walmart->id,
            'posted_at' => '2026-07-10',
            'amount' => -30.00,
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'W-1',
            'ordered_at' => '2026-07-01 00:00:00',
            'total' => 40.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'W-2',
            'ordered_at' => '2026-08-04 00:00:00',
            'total' => 102.43,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'A-1',
            'ordered_at' => '2026-07-15 00:00:00',
            'total' => 15.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $otherUser->id,
            'merchant_id' => $otherWalmart->id,
            'order_number' => 'OTHER-1',
            'ordered_at' => '2026-07-20 00:00:00',
            'total' => 12.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->where('bankCoverage.min', '2026-06-01')
                ->where('bankCoverage.max', '2026-08-06')
                ->has('retailers', 2)
                ->where('retailers.0.normalized_name', 'walmart')
                ->where('retailers.0.name', 'Walmart')
                ->where('retailers.0.type', Merchant::RETAILER)
                ->where('retailers.0.order_count', 2)
                ->where('retailers.0.min_ordered_at', '2026-07-01')
                ->where('retailers.0.max_ordered_at', '2026-08-04')
                ->where('retailers.0.coverage_span_days', 34)
                ->where('retailers.1.normalized_name', 'amazon')
                ->where('retailers.1.name', 'Amazon')
                ->where('retailers.1.order_count', 1)
                ->where('retailers.1.min_ordered_at', '2026-07-15')
                ->where('retailers.1.max_ordered_at', '2026-07-15')
                ->has('otherMerchants', 1)
                ->where('otherMerchants.0.id', $target->id)
                ->where('otherMerchants.0.name', 'Target')
                ->where('otherMerchants.0.transaction_count', 2)
                ->where('otherMerchants.0.min_posted_at', '2026-06-01')
                ->where('otherMerchants.0.max_posted_at', '2026-08-06')
                ->where('filters.q', ''));
    }

    public function test_index_search_filters_other_merchants(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);

        $target = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Target',
            'normalized_name' => 'target',
            'supports_order_import' => false,
        ]);

        $starbucks = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Starbucks',
            'normalized_name' => 'starbucks',
            'type' => Merchant::RESTAURANT,
            'supports_order_import' => false,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $target->id,
            'posted_at' => '2026-07-01',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $starbucks->id,
            'posted_at' => '2026-07-02',
        ]);

        $this->actingAs($user)
            ->get(route('orders.index', ['q' => 'Star']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->has('otherMerchants', 1)
                ->where('otherMerchants.0.id', $starbucks->id)
                ->where('filters.q', 'Star'));
    }

    public function test_show_lists_walmart_orders_with_coverage_and_edge_flags(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $target = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Target',
            'normalized_name' => 'target',
        ]);

        $otherWalmart = Merchant::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $account = Account::factory()->create(['is_active' => true]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-06-01',
            'amount' => -10.00,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-06',
            'amount' => -20.00,
        ]);

        $nearEdgeOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'NEAR-EDGE-1',
            'ordered_at' => '2026-08-04 00:00:00',
            'delivered_at' => '2026-08-05 00:00:00',
            'total' => 102.43,
            'payment_last_four' => '2525',
            'status' => 'imported',
        ]);

        $safeOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'SAFE-1',
            'ordered_at' => '2026-07-01 00:00:00',
            'delivered_at' => '2026-07-02 00:00:00',
            'total' => 40.00,
            'payment_last_four' => '2525',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $target->id,
            'order_number' => 'TARGET-1',
            'ordered_at' => '2026-07-15 00:00:00',
            'total' => 15.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $otherUser->id,
            'merchant_id' => $otherWalmart->id,
            'order_number' => 'OTHER-1',
            'ordered_at' => '2026-07-20 00:00:00',
            'total' => 12.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', 'walmart'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('merchant.normalized_name', 'walmart')
                ->where('merchant.name', 'Walmart')
                ->where('filters.merchant', 'walmart')
                ->where('filters.q', '')
                ->where('bankCoverage.min', '2026-06-01')
                ->where('bankCoverage.max', '2026-08-06')
                ->where('orderCoverage.min', '2026-07-01')
                ->where('orderCoverage.max', '2026-08-04')
                ->where('nearImportEdge', true)
                ->has('orders', 2)
                ->where('orders.0.id', $nearEdgeOrder->id)
                ->where('orders.0.near_import_edge', true)
                ->where('orders.1.id', $safeOrder->id)
                ->where('orders.1.near_import_edge', false));
    }

    public function test_show_lists_amazon_orders(): void
    {
        $user = User::factory()->create();

        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $amazonOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'AMZ-1',
            'ordered_at' => '2026-07-01 00:00:00',
            'total' => 25.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'WMT-1',
            'ordered_at' => '2026-07-02 00:00:00',
            'total' => 30.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', 'amazon'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('merchant.normalized_name', 'amazon')
                ->has('orders', 1)
                ->where('orders.0.id', $amazonOrder->id)
                ->where('orders.0.order_number', 'AMZ-1'));
    }

    public function test_show_search_filters_orders_by_number(): void
    {
        $user = User::factory()->create();

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'FIND-ME-123',
            'ordered_at' => '2026-07-01 00:00:00',
            'total' => 25.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'OTHER-999',
            'ordered_at' => '2026-07-02 00:00:00',
            'total' => 30.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'q' => 'FIND-ME']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'FIND-ME-123')
                ->where('filters.q', 'FIND-ME'));
    }

    public function test_show_rejects_unknown_merchants(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/orders/target')
            ->assertNotFound();
    }
}
