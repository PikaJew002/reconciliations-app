<?php

namespace Tests\Feature\Merchants;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MerchantBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_merchants_show(): void
    {
        $merchant = Merchant::factory()->create([
            'supports_order_import' => false,
        ]);

        $this->get(route('merchants.show', $merchant))
            ->assertRedirect('/login');
    }

    public function test_show_returns_404_for_order_import_retailers(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $walmart->id,
            'posted_at' => '2026-07-01',
        ]);

        $this->actingAs($user)
            ->get(route('merchants.show', $walmart))
            ->assertNotFound();
    }

    public function test_show_returns_404_when_user_has_no_activity_on_merchant(): void
    {
        $user = User::factory()->create();

        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);

        $this->actingAs($user)
            ->get(route('merchants.show', $merchant))
            ->assertNotFound();
    }

    public function test_show_lists_user_transactions_and_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::factory()->create([
            'name' => 'Capital One Card',
            'is_active' => true,
        ]);

        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Target',
            'normalized_name' => 'target',
            'type' => Merchant::RETAILER,
            'supports_order_import' => false,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-06-01',
            'description' => 'TARGET STORE A',
            'amount' => -20.00,
            'status' => 'unmatched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-04',
            'description' => 'TARGET STORE B',
            'amount' => -35.00,
            'status' => 'matched',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $otherUser->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-05',
            'description' => 'OTHER USER TX',
            'amount' => -1.00,
        ]);

        $this->actingAs($user)
            ->get(route('merchants.show', $merchant))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Merchants/Show')
                ->where('merchant.id', $merchant->id)
                ->where('merchant.name', 'Target')
                ->where('merchant.transaction_count', 2)
                ->where('merchant.min_posted_at', '2026-06-01')
                ->where('merchant.max_posted_at', '2026-08-04')
                ->has('transactions', 2)
                ->where('transactions.0.description', 'TARGET STORE B')
                ->where('transactions.0.account.name', 'Capital One Card')
                ->where('transactionsTruncated', false));
    }

    public function test_show_search_filters_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);

        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => false,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-01',
            'description' => 'TARGET STORE',
            'amount' => -20.00,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-08-02',
            'description' => 'TARGET ONLINE',
            'amount' => -45.00,
        ]);

        $this->actingAs($user)
            ->get(route('merchants.show', ['merchant' => $merchant, 'q' => 'ONLINE']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Merchants/Show')
                ->has('transactions', 1)
                ->where('transactions.0.description', 'TARGET ONLINE')
                ->where('filters.q', 'ONLINE'));
    }
}
