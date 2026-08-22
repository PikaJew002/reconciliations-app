<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_accounts_edit(): void
    {
        $account = Account::factory()->create();

        $this->get(route('accounts.edit', $account))
            ->assertRedirect('/login');
    }

    public function test_guests_cannot_update_accounts(): void
    {
        $account = Account::factory()->create([
            'name' => 'Original',
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
        ]);

        $this->put(route('accounts.update', $account), [
            'name' => 'Changed',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_BILL,
            'currency' => 'USD',
        ])->assertRedirect('/login');

        $this->assertSame('Original', $account->fresh()->name);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $account->fresh()->default_classification);
    }

    public function test_authenticated_user_can_view_edit_form(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Joint Account 1',
            'default_classification' => BankTransaction::CLASSIFICATION_BILL,
        ]);

        $this->actingAs($user)
            ->get(route('accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Edit')
                ->where('account.id', $account->id)
                ->where('account.name', 'Joint Account 1')
                ->where('account.default_classification', BankTransaction::CLASSIFICATION_BILL)
                ->has('institutions')
                ->has('accountTypes', 4)
                ->has('defaultClassifications', 2));
    }

    public function test_authenticated_user_cannot_edit_other_users_accounts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other Account',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.edit', $account))
            ->assertForbidden();

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Hijacked',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'USD',
        ])->assertForbidden();

        $this->assertSame('Other Account', $account->fresh()->name);
    }

    public function test_authenticated_user_can_update_an_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Joint Account 1',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Joint Bills',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_name' => 'Checking',
            'account_type' => Account::CHECKING,
            'default_classification' => BankTransaction::CLASSIFICATION_BILL,
            'currency' => 'usd',
            'last_four' => '1234',
        ]);

        $account->refresh();

        $this->assertSame('Joint Bills', $account->name);
        $this->assertSame('Checking', $account->account_name);
        $this->assertSame(BankTransaction::CLASSIFICATION_BILL, $account->default_classification);
        $this->assertSame('USD', $account->currency);
        $this->assertSame('1234', $account->last_four);

        $response
            ->assertRedirect(route('accounts.edit', $account))
            ->assertSessionHas('success');
    }

    public function test_update_defaults_classification_to_expense_when_omitted(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'default_classification' => BankTransaction::CLASSIFICATION_BILL,
        ]);

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => $account->name,
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'currency' => 'USD',
        ])->assertRedirect(route('accounts.edit', $account));

        $this->assertSame(
            BankTransaction::CLASSIFICATION_EXPENSE,
            $account->fresh()->default_classification,
        );
    }

    public function test_authenticated_user_cannot_edit_off_book_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->offBook()->create();

        $this->actingAs($user)
            ->get(route('accounts.edit', $account))
            ->assertForbidden();

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'name' => 'Hijacked',
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'currency' => 'USD',
        ])->assertForbidden();

        $this->assertSame(Account::OFF_BOOK_NAME, $account->fresh()->name);
        $this->assertTrue($account->fresh()->isOffBook());
    }
}
