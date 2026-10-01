<?php

namespace Tests\Feature\Browser;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\Browser\BrowserBaselineSeeder;
use Database\Seeders\Browser\BrowserUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class BrowserUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_browser_user_with_baseline_data(): void
    {
        $other = User::factory()->create();
        $otherAccount = Account::factory()->for($other)->create();

        $this->artisan('browser:user')
            ->expectsOutputToContain('Email: '.BrowserUser::EMAIL)
            ->expectsOutputToContain('Password: '.BrowserUser::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->where('email', BrowserUser::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->onboarding_hidden_at);
        $this->assertTrue(Auth::attempt([
            'email' => BrowserUser::EMAIL,
            'password' => BrowserUser::PASSWORD,
        ]));

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'external_id' => BrowserBaselineSeeder::CHECKING_EXTERNAL_ID,
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'external_id' => BrowserBaselineSeeder::CREDIT_CARD_EXTERNAL_ID,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'kind' => Category::KIND_BILL,
            'name' => 'Rent',
        ]);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'kind' => Category::KIND_EXPENSE,
            'name' => 'Groceries',
        ]);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'kind' => Category::KIND_INCOME,
            'name' => 'Paycheck',
        ]);
        $this->assertDatabaseMissing('bank_transactions', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('accounts', [
            'id' => $otherAccount->id,
        ]);
    }

    public function test_command_resets_existing_browser_user_data_back_to_baseline(): void
    {
        $user = User::factory()->create([
            'email' => BrowserUser::EMAIL,
        ]);
        $account = Account::factory()->for($user)->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);
        Category::findOrCreateForUser($user->id, Category::KIND_EXPENSE, 'Scenario Marker');

        $this->artisan('browser:user')->assertSuccessful();

        $user->refresh();

        $this->assertSame(2, Account::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('bank_transactions', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('categories', [
            'user_id' => $user->id,
            'name' => 'Scenario Marker',
        ]);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Groceries',
        ]);
    }

    public function test_command_runs_a_scenario_after_the_baseline(): void
    {
        $this->artisan('browser:user', [
            '--scenario' => 'CommandTest',
        ])->expectsOutputToContain('Scenario: CommandTest')
            ->assertSuccessful();

        $user = User::query()->where('email', BrowserUser::EMAIL)->first();

        $this->assertNotNull($user);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Scenario Marker',
        ]);
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'external_id' => BrowserBaselineSeeder::CHECKING_EXTERNAL_ID,
        ]);
    }

    public function test_unknown_scenario_fails_without_wiping_the_browser_user(): void
    {
        $user = User::factory()->create([
            'email' => BrowserUser::EMAIL,
        ]);
        $account = Account::factory()->for($user)->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
        ]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);

        $this->artisan('browser:user', [
            '--scenario' => 'MissingCase',
        ])->assertFailed();

        $this->assertDatabaseHas('bank_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_command_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('browser:user')->assertFailed();

        $this->assertDatabaseMissing('users', [
            'email' => BrowserUser::EMAIL,
        ]);
    }

    public function test_list_does_not_create_a_user(): void
    {
        $this->artisan('browser:user', [
            '--list' => true,
        ])->expectsOutputToContain('No scenario seeders')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', [
            'email' => BrowserUser::EMAIL,
        ]);
    }
}

namespace Database\Seeders\Browser;

use App\Models\Category;
use App\Models\User;

class CommandTestScenario extends BrowserScenarioSeeder
{
    public function run(User $user): void
    {
        Category::findOrCreateForUser($user->id, Category::KIND_EXPENSE, 'Scenario Marker');
    }
}
