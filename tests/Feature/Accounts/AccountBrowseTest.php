<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_accounts_index(): void
    {
        $this->get(route('accounts.index'))
            ->assertRedirect('/login');
    }

    public function test_guests_are_redirected_from_accounts_show(): void
    {
        $account = Account::factory()->create(['is_active' => true]);

        $this->get(route('accounts.show', $account))
            ->assertRedirect('/login');
    }

    public function test_index_shows_only_accounts_with_user_transactions_and_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userAccount = Account::factory()->create([
            'name' => 'Capital One Card',
            'institution_name' => 'Capital One',
            'last_four' => '5394',
            'is_active' => true,
        ]);

        $otherAccount = Account::factory()->create([
            'name' => 'Other Checking',
            'institution_name' => 'Other Bank',
            'last_four' => '1111',
            'is_active' => true,
        ]);

        $unusedAccount = Account::factory()->create([
            'name' => 'Unused Cash',
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $userAccount->id,
            'posted_at' => '2026-06-01',
            'amount' => -12.34,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $userAccount->id,
            'posted_at' => '2026-08-06',
            'amount' => -56.78,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $otherUser->id,
            'account_id' => $otherAccount->id,
            'posted_at' => '2026-07-15',
            'amount' => -9.99,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->where('bankCoverage.min', '2026-06-01')
                ->where('bankCoverage.max', '2026-08-06')
                ->has('accounts', 1)
                ->where('accounts.0.id', $userAccount->id)
                ->where('accounts.0.name', 'Capital One Card')
                ->where('accounts.0.transaction_count', 2)
                ->where('accounts.0.min_posted_at', '2026-06-01')
                ->where('accounts.0.max_posted_at', '2026-08-06')
                ->where('accounts.0.coverage_span_days', 66)
                ->where('filters.q', ''));

        $this->assertDatabaseHas('accounts', ['id' => $unusedAccount->id]);
    }

    public function test_index_search_filters_accounts(): void
    {
        $user = User::factory()->create();

        $capitalOne = Account::factory()->create([
            'name' => 'Capital One Card',
            'institution_name' => 'Capital One',
            'last_four' => '5394',
            'is_active' => true,
        ]);

        $checking = Account::factory()->create([
            'name' => 'CVNB Checking',
            'institution_name' => 'CVNB',
            'last_four' => '6218',
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $capitalOne->id,
            'posted_at' => '2026-07-01',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'posted_at' => '2026-07-02',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.index', ['q' => 'Capital']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->has('accounts', 1)
                ->where('accounts.0.id', $capitalOne->id)
                ->where('filters.q', 'Capital'));
    }

    public function test_show_returns_404_when_user_has_no_activity_on_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get(route('accounts.show', $account))
            ->assertNotFound();
    }

    public function test_show_lists_user_transactions_and_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::factory()->create([
            'name' => 'Capital One Card',
            'institution_name' => 'Capital One',
            'last_four' => '5394',
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-06-01',
            'description' => 'WALMART.COM ORDER',
            'amount' => -90.66,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-04',
            'description' => 'TARGET STORE',
            'amount' => -20.00,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $otherUser->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-05',
            'description' => 'OTHER USER TX',
            'amount' => -1.00,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Show')
                ->where('account.id', $account->id)
                ->where('account.transaction_count', 2)
                ->where('account.min_posted_at', '2026-06-01')
                ->where('account.max_posted_at', '2026-08-04')
                ->has('transactions', 2)
                ->where('transactions.0.description', 'TARGET STORE')
                ->where('transactionsTruncated', false));
    }

    public function test_show_search_filters_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['is_active' => true]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-01',
            'description' => 'WALMART.COM ORDER',
            'amount' => -90.66,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-02',
            'description' => 'TARGET STORE',
            'amount' => -20.00,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', ['account' => $account, 'q' => 'WALMART']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Show')
                ->has('transactions', 1)
                ->where('transactions.0.description', 'WALMART.COM ORDER')
                ->where('filters.q', 'WALMART'));
    }
}
