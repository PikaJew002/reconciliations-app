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

    public function test_index_defaults_to_walmart_orders_with_coverage_and_edge_flags(): void
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
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
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

    public function test_index_search_filters_orders_by_number(): void
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
            ->get(route('orders.index', ['q' => 'FIND-ME']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'FIND-ME-123')
                ->where('filters.q', 'FIND-ME'));
    }
}
