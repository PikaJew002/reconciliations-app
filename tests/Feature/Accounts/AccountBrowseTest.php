<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\VenmoActivity;
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

    public function test_index_shows_only_owned_accounts_including_empty_with_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $userAccount = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Capital One Card',
            'institution_name' => 'Capital One',
            'last_four' => '5394',
            'is_active' => true,
        ]);

        Account::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other Checking',
            'institution_name' => 'Other Bank',
            'last_four' => '1111',
            'is_active' => true,
        ]);

        $unusedAccount = Account::factory()->create([
            'user_id' => $user->id,
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
            'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
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
                ->has('accounts', 2)
                ->where('accounts.0.id', $userAccount->id)
                ->where('accounts.0.name', 'Capital One Card')
                ->where('accounts.0.transaction_count', 2)
                ->where('accounts.0.min_posted_at', '2026-06-01')
                ->where('accounts.0.max_posted_at', '2026-08-06')
                ->where('accounts.0.coverage_span_days', 66)
                ->where('accounts.1.id', $unusedAccount->id)
                ->where('accounts.1.transaction_count', 0)
                ->where('filters.q', ''));
    }

    public function test_index_marks_off_book_accounts(): void
    {
        $user = User::factory()->create();
        $tracked = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'is_active' => true,
        ]);
        $offBook = Account::factory()->for($user)->offBook()->create();

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->has('accounts', 2)
                ->where('accounts.0.id', $tracked->id)
                ->where('accounts.0.is_off_book', false)
                ->where('accounts.1.id', $offBook->id)
                ->where('accounts.1.is_off_book', true)
                ->where('accounts.1.name', Account::OFF_BOOK_NAME));
    }

    public function test_index_search_filters_accounts(): void
    {
        $user = User::factory()->create();

        $capitalOne = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Capital One Card',
            'institution_name' => 'Capital One',
            'last_four' => '5394',
            'is_active' => true,
        ]);

        Account::factory()->create([
            'user_id' => $user->id,
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

        $this->actingAs($user)
            ->get(route('accounts.index', ['q' => 'Capital']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->has('accounts', 1)
                ->where('accounts.0.id', $capitalOne->id)
                ->where('filters.q', 'Capital'));
    }

    public function test_show_allows_accounts_with_no_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Empty Account',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Show')
                ->where('account.id', $account->id)
                ->where('account.transaction_count', 0)
                ->has('transactions', 0)
                ->where('pagination.total', 0)
                ->where('pagination.current_page', 1)
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_forbids_other_users_accounts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $otherUser->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', $account))
            ->assertForbidden();
    }

    public function test_show_lists_user_transactions_and_coverage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $account = Account::factory()->create([
            'user_id' => $user->id,
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
            'status' => 'ignored',
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_MANUAL,
            'classification_confidence' => 100,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $otherUser->id,
            'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
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
                ->where('transactions.0.classification', BankTransaction::CLASSIFICATION_EXPENSE)
                ->where('transactions.0.classification_source', BankTransaction::CLASSIFICATION_SOURCE_MANUAL)
                ->where('pagination.total', 2)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 1)
                ->where('pagination.per_page', 50)
                ->missing('transactionsTruncated'));
    }

    public function test_show_search_filters_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

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
                ->where('filters.q', 'WALMART')
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_date_filters_are_inclusive_and_echoed_in_props(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-06-30',
            'description' => 'JUNE',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-01',
            'description' => 'JULY START',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-31',
            'description' => 'JULY END',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-01',
            'description' => 'AUGUST',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', [
                'account' => $account,
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Show')
                ->has('transactions', 2)
                ->where('transactions.0.description', 'JULY END')
                ->where('transactions.1.description', 'JULY START')
                ->where('pagination.total', 2)
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-07-31')
                ->where('filters.q', ''));
    }

    public function test_show_from_and_to_can_be_used_independently(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-06-01',
            'description' => 'JUNE',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-15',
            'description' => 'JULY',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-20',
            'description' => 'AUGUST',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', ['account' => $account, 'from' => '2026-07-15']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 2)
                ->where('transactions.0.description', 'AUGUST')
                ->where('transactions.1.description', 'JULY')
                ->where('filters.from', '2026-07-15')
                ->where('filters.to', ''));

        $this->actingAs($user)
            ->get(route('accounts.show', ['account' => $account, 'to' => '2026-07-15']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 2)
                ->where('transactions.0.description', 'JULY')
                ->where('transactions.1.description', 'JUNE')
                ->where('filters.from', '')
                ->where('filters.to', '2026-07-15'));
    }

    public function test_show_search_and_date_filters_combine(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-10',
            'description' => 'WALMART JULY',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-08-10',
            'description' => 'WALMART AUGUST',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-12',
            'description' => 'TARGET JULY',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', [
                'account' => $account,
                'q' => 'WALMART',
                'from' => '2026-07-01',
                'to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 1)
                ->where('transactions.0.description', 'WALMART JULY')
                ->where('filters.q', 'WALMART')
                ->where('filters.from', '2026-07-01')
                ->where('filters.to', '2026-07-31'));
    }

    public function test_show_ignores_invalid_date_filters(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'posted_at' => '2026-07-15',
            'description' => 'JULY',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', [
                'account' => $account,
                'from' => 'not-a-date',
                'to' => '2026-13-40',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 1)
                ->where('filters.from', '')
                ->where('filters.to', ''));
    }

    public function test_show_paginates_transactions_and_preserves_filters(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        foreach (range(0, 50) as $offset) {
            BankTransaction::factory()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'import_batch_id' => $batch->id,
                'posted_at' => now()->setDate(2026, 1, 1)->addDays($offset)->toDateString(),
                'description' => "TX {$offset}",
            ]);
        }

        $this->actingAs($user)
            ->get(route('accounts.show', [
                'account' => $account,
                'from' => '2026-01-01',
                'to' => '2026-02-28',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 50)
                ->where('transactions.0.description', 'TX 50')
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 51)
                ->where('pagination.per_page', 50)
                ->where('pagination.first_item', 1)
                ->where('pagination.last_item', 50)
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-02-28'));

        $this->actingAs($user)
            ->get(route('accounts.show', [
                'account' => $account,
                'from' => '2026-01-01',
                'to' => '2026-02-28',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 1)
                ->where('transactions.0.description', 'TX 0')
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 51)
                ->where('pagination.first_item', 51)
                ->where('pagination.last_item', 51)
                ->where('filters.from', '2026-01-01')
                ->where('filters.to', '2026-02-28'));
    }

    public function test_show_includes_venmo_summary_and_search_matches_statement_note(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'posted_at' => '2026-06-06',
            'description' => 'VENMO PURCHASE 1051937135825',
            'amount' => -250.00,
        ]);

        VenmoActivity::factory()->cardPayment('2195', -250.00)->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'bank_transaction_id' => $transaction->id,
            'match_status' => VenmoActivity::STATUS_CONFIRMED,
            'note' => 'Extreme',
            'to_name' => 'Tyler Adams',
            'occurred_at' => '2026-06-05 19:11:43',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.0.description', 'VENMO PURCHASE 1051937135825')
                ->where('transactions.0.venmo_summary', 'Tyler Adams · Extreme'));

        $this->actingAs($user)
            ->get(route('accounts.show', ['account' => $account, 'q' => 'Extreme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions', 1)
                ->where('transactions.0.venmo_summary', 'Tyler Adams · Extreme')
                ->where('filters.q', 'Extreme'));
    }
}
