<?php

namespace Tests\Feature\Orders;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
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

        $outsideCoverageOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'OUTSIDE-1',
            'ordered_at' => '2026-08-08 00:00:00',
            'delivered_at' => '2026-08-09 00:00:00',
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

        $beforeCoverageOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => 'BEFORE-1',
            'ordered_at' => '2026-05-29 00:00:00',
            'delivered_at' => '2026-05-30 00:00:00',
            'total' => 25.00,
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
                ->where('orderCoverage.min', '2026-05-29')
                ->where('orderCoverage.max', '2026-08-08')
                ->where('nearImportEdge', true)
                ->has('orders', 3)
                ->where('orders.0.id', $outsideCoverageOrder->id)
                ->where('orders.0.near_import_edge', true)
                ->where('orders.1.id', $safeOrder->id)
                ->where('orders.1.near_import_edge', false)
                ->where('orders.2.id', $beforeCoverageOrder->id)
                ->where('orders.2.near_import_edge', false)
                ->where('pagination.total', 3)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 1)
                ->where('pagination.per_page', 50)
                ->where('filters.from', '')
                ->where('filters.to', '')
                ->where('filters.state', '')
                ->missing('ordersTruncated'));
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

    public function test_show_flags_unbalanced_orders(): void
    {
        $user = User::factory()->create();

        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);

        $balanced = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'BALANCED',
            'ordered_at' => '2026-07-02 00:00:00',
            'total' => 10.00,
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $balanced->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
            'category_id' => null,
        ]);

        $unbalanced = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => 'UNBALANCED',
            'ordered_at' => '2026-07-03 00:00:00',
            'total' => 42.30,
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $unbalanced->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 36.80,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', 'amazon'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 2)
                ->where('orders.0.id', $unbalanced->id)
                ->where('orders.0.components_balanced', false)
                ->where('orders.0.component_sum', 36.8)
                ->where('orders.0.gap', 5.5)
                ->where('orders.1.id', $balanced->id)
                ->where('orders.1.components_balanced', true)
                ->where('orders.1.component_sum', 10)
                ->where('orders.1.gap', 0));
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
                ->where('filters.q', 'FIND-ME')
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_search_ignores_hyphens_in_order_numbers(): void
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
            'order_number' => '200013093198585',
            'ordered_at' => '2026-07-01 00:00:00',
            'total' => 25.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => '111-0000001-0000001',
            'ordered_at' => '2026-07-02 00:00:00',
            'total' => 30.00,
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => '999-0000000-0000000',
            'ordered_at' => '2026-07-03 00:00:00',
            'total' => 40.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'q' => '2000130-93198585']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 1)
                ->where('orders.0.order_number', '200013093198585')
                ->where('filters.q', '2000130-93198585'));

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'q' => '11100000010000001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 1)
                ->where('orders.0.order_number', '111-0000001-0000001')
                ->where('filters.q', '11100000010000001'));

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'q' => '111-0000001-0000001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 1)
                ->where('orders.0.order_number', '111-0000001-0000001'));
    }

    public function test_show_allows_merchants_with_no_orders(): void
    {
        $user = User::factory()->create();

        Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', 'walmart'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('merchant.normalized_name', 'walmart')
                ->where('merchant.order_count', 0)
                ->has('orders', 0)
                ->where('pagination.total', 0)
                ->where('pagination.current_page', 1)
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_date_filters_are_inclusive_and_echoed_in_props(): void
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
            'ordered_at' => '2026-06-30 12:00:00',
            'order_number' => 'JUNE',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-01 08:00:00',
            'order_number' => 'JULY START',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-31 23:59:59',
            'order_number' => 'JULY END',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-08-01 00:00:00',
            'order_number' => 'AUGUST',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->has('orders', 2)
                ->where('orders.0.order_number', 'JULY END')
                ->where('orders.1.order_number', 'JULY START')
                ->where('pagination.total', 2)
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-07-31')
                ->where('filters.q', ''));
    }

    public function test_show_from_and_to_can_be_used_independently(): void
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
            'ordered_at' => '2026-06-01 00:00:00',
            'order_number' => 'JUNE',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-15 00:00:00',
            'order_number' => 'JULY',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-08-20 00:00:00',
            'order_number' => 'AUGUST',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'from' => '2026-07-15']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 2)
                ->where('orders.0.order_number', 'AUGUST')
                ->where('orders.1.order_number', 'JULY')
                ->where('filters.from', '2026-07-15')
                ->where('filters.to', ''));

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'to' => '2026-07-15']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 2)
                ->where('orders.0.order_number', 'JULY')
                ->where('orders.1.order_number', 'JUNE')
                ->where('filters.from', '')
                ->where('filters.to', '2026-07-15'));
    }

    public function test_show_search_and_date_filters_combine(): void
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
            'ordered_at' => '2026-07-10 00:00:00',
            'order_number' => 'WMT-JULY-123',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-08-10 00:00:00',
            'order_number' => 'WMT-AUG-123',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-12 00:00:00',
            'order_number' => 'OTHER-JULY',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'q' => 'WMT',
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.order_number', 'WMT-JULY-123')
                ->where('filters.q', 'WMT')
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-07-31'));
    }

    public function test_show_ignores_invalid_date_filters(): void
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
            'ordered_at' => '2026-07-15 00:00:00',
            'order_number' => 'JULY',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'from' => 'not-a-date',
                'to' => '2026-13-40',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_paginates_orders_and_preserves_filters(): void
    {
        $user = User::factory()->create();

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        foreach (range(0, 50) as $offset) {
            Order::factory()->create([
                'user_id' => $user->id,
                'merchant_id' => $walmart->id,
                'import_batch_id' => $batch->id,
                'ordered_at' => now()->setDate(2026, 1, 1)->addDays($offset)->toDateString(),
                'order_number' => sprintf('ORD-%02d', $offset),
            ]);
        }

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'from' => '2026-01-01',
                'to' => '2026-02-28',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 50)
                ->where('orders.0.order_number', 'ORD-50')
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 51)
                ->where('pagination.per_page', 50)
                ->where('pagination.first_item', 1)
                ->where('pagination.last_item', 50)
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-02-28'));

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'from' => '2026-01-01',
                'to' => '2026-02-28',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.order_number', 'ORD-00')
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 51)
                ->where('pagination.first_item', 51)
                ->where('pagination.last_item', 51)
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-02-28'));
    }

    public function test_show_imported_state_excludes_reconciled_orders(): void
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
            'ordered_at' => '2026-07-02 00:00:00',
            'order_number' => 'IMPORTED',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-03 00:00:00',
            'order_number' => 'RECONCILED',
            'status' => 'reconciled',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'state' => 'imported']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.order_number', 'IMPORTED')
                ->where('orders.0.status', 'imported')
                ->where('pagination.total', 1)
                ->where('filters.state', 'imported'));
    }

    public function test_show_unbalanced_state_matches_payable_component_gap(): void
    {
        $user = User::factory()->create();

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $balanced = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-01 00:00:00',
            'order_number' => 'BALANCED',
            'total' => 10.00,
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $balanced->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
        ]);

        $bankRefundBalances = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-02 00:00:00',
            'order_number' => 'BANK-REFUND',
            'total' => 8.00,
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $bankRefundBalances->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
            'refund_amount' => 2.00,
            'refund_kind' => OrderComponent::REFUND_KIND_BANK,
        ]);

        $offBookStillUnbalanced = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-03 00:00:00',
            'order_number' => 'OFF-BOOK',
            'total' => 8.00,
            'status' => 'reconciled',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $offBookStillUnbalanced->id,
            'order_item_id' => null,
            'type' => 'product',
            'amount' => 10.00,
            'refund_amount' => 2.00,
            'refund_kind' => OrderComponent::REFUND_KIND_OFF_BOOK,
        ]);

        $missingComponents = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-04 00:00:00',
            'order_number' => 'NO-COMPONENTS',
            'total' => 12.00,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'state' => 'unbalanced']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 2)
                ->where('orders.0.order_number', 'NO-COMPONENTS')
                ->where('orders.0.id', $missingComponents->id)
                ->where('orders.0.components_balanced', false)
                ->where('orders.1.order_number', 'OFF-BOOK')
                ->where('orders.1.components_balanced', false)
                ->where('pagination.total', 2)
                ->where('filters.state', 'unbalanced'));
    }

    public function test_show_state_filter_combines_with_dates_and_is_kept_on_later_pages(): void
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
            'ordered_at' => '2026-06-01 00:00:00',
            'order_number' => 'JUNE-IMPORTED',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-15 00:00:00',
            'order_number' => 'JULY-RECONCILED',
            'status' => 'reconciled',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', [
                'merchant' => 'walmart',
                'from' => '2026-07-01',
                'state' => 'imported',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 0)
                ->where('pagination.total', 0)
                ->where('pagination.current_page', 2)
                ->where('filters.from', '2026-07-01')
                ->where('filters.state', 'imported'));
    }

    public function test_show_ignores_invalid_state_filter(): void
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
            'ordered_at' => '2026-07-15 00:00:00',
            'order_number' => 'IMPORTED',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'ordered_at' => '2026-07-16 00:00:00',
            'order_number' => 'RECONCILED',
            'status' => 'reconciled',
        ]);

        $this->actingAs($user)
            ->get(route('orders.show', ['merchant' => 'walmart', 'state' => 'closed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 2)
                ->where('filters.state', ''));
    }

    public function test_show_rejects_unknown_merchants(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/orders/target')
            ->assertNotFound();
    }
}
