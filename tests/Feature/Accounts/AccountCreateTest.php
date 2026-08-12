<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_accounts_create(): void
    {
        $this->get(route('accounts.create'))
            ->assertRedirect('/login');
    }

    public function test_guests_cannot_store_accounts(): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Capital One Card',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
            'currency' => 'USD',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_authenticated_user_can_view_create_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Create')
                ->has('institutions')
                ->has('accountTypes', 4)
                ->has('defaultClassifications', 2)
                ->where('institutions.0', CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME));
    }

    public function test_authenticated_user_can_create_an_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'name' => 'Capital One Card',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_name' => 'Rewards Card',
            'account_type' => Account::CREDIT_CARD,
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'usd',
            'last_four' => '5394',
        ]);

        $account = Account::query()->first();

        $this->assertNotNull($account);
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('Capital One Card', $account->name);
        $this->assertSame(CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME, $account->institution_name);
        $this->assertSame('Rewards Card', $account->account_name);
        $this->assertSame(Account::CREDIT_CARD, $account->account_type);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $account->default_classification);
        $this->assertSame('USD', $account->currency);
        $this->assertSame('5394', $account->last_four);
        $this->assertTrue($account->is_active);

        $response
            ->assertRedirect(route('accounts.imports.index', $account))
            ->assertSessionHas('success');
    }

    public function test_store_defaults_classification_to_expense_when_omitted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'name' => 'Joint Account 1',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'currency' => 'USD',
        ]);

        $account = Account::query()->first();

        $this->assertNotNull($account);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $account->default_classification);
        $response->assertRedirect(route('accounts.imports.index', $account));
    }

    public function test_authenticated_user_can_create_an_account_with_bill_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'name' => 'Joint Account 1',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_BILL,
            'currency' => 'USD',
        ]);

        $account = Account::query()->first();

        $this->assertNotNull($account);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $account->default_classification);
        $response->assertRedirect(route('accounts.imports.index', $account));
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('accounts.create'))
            ->post(route('accounts.store'), [])
            ->assertRedirect(route('accounts.create'))
            ->assertSessionHasErrors(['name', 'institution_name', 'account_type', 'currency']);

        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_allows_optional_fields_to_be_blank(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('accounts.store'), [
            'name' => 'Cash Wallet',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_name' => '',
            'account_type' => Account::CASH,
            'currency' => 'USD',
            'last_four' => '',
        ]);

        $account = Account::query()->first();

        $this->assertNotNull($account);
        $this->assertNull($account->account_name);
        $this->assertNull($account->last_four);
        $response->assertRedirect(route('accounts.imports.index', $account));
    }
}
