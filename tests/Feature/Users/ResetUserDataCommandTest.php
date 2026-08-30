<?php

namespace Tests\Feature\Users;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResetUserDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_wipes_one_user_and_keeps_the_login(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'email' => 'onboarding@example.com',
            'onboarding_hidden_at' => now(),
            'onboarding_skipped_steps' => ['import-orders'],
            'onboarding_tours' => ['import-bank' => 'completed'],
            'leftover_starts_on' => '2026-08-01',
        ]);
        $other = User::factory()->create();

        $account = Account::factory()->for($user)->create();
        $otherAccount = Account::factory()->for($other)->create();

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => 'imports/bank.csv',
        ]);
        Storage::disk('local')->put($batch->storage_path, 'csv');

        $amazonBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'storage_path' => 'imports/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/summary.csv',
            'metadata' => [
                'items_path' => 'imports/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/items.csv',
            ],
        ]);
        Storage::disk('local')->put($amazonBatch->storage_path, 'summary');
        Storage::disk('local')->put($amazonBatch->metadata['items_path'], 'items');

        $otherBatch = ImportBatch::factory()->create([
            'user_id' => $other->id,
            'storage_path' => 'imports/other.csv',
        ]);
        Storage::disk('local')->put($otherBatch->storage_path, 'other');

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $other->id,
            'account_id' => $otherAccount->id,
            'import_batch_id' => $otherBatch->id,
        ]);

        Category::factory()->for($user)->expense()->create();
        $otherCategory = Category::factory()->for($other)->expense()->create();

        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $amazonBatch->id,
            'merchant_id' => $merchant->id,
        ]);

        $this->artisan('user:reset-data', [
            'user' => 'onboarding@example.com',
            '--force' => true,
        ])->assertSuccessful();

        $user->refresh();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'onboarding@example.com']);
        $this->assertNull($user->onboarding_hidden_at);
        $this->assertNull($user->onboarding_skipped_steps);
        $this->assertNull($user->onboarding_tours);
        $this->assertNull($user->leftover_starts_on);

        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseHas('accounts', ['id' => $otherAccount->id]);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseHas('import_batches', ['id' => $otherBatch->id]);
        $this->assertDatabaseHas('bank_transactions', ['user_id' => $other->id]);
        $this->assertDatabaseMissing('bank_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('categories', ['id' => $otherCategory->id]);
        $this->assertDatabaseMissing('categories', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('orders', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('merchants', ['user_id' => $user->id]);

        Storage::disk('local')->assertMissing('imports/bank.csv');
        Storage::disk('local')->assertMissing('imports/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/summary.csv');
        Storage::disk('local')->assertMissing('imports/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/items.csv');
        Storage::disk('local')->assertExists('imports/other.csv');
    }

    public function test_command_fails_when_user_is_missing(): void
    {
        $this->artisan('user:reset-data', [
            'user' => 'missing@example.com',
            '--force' => true,
        ])->assertFailed();
    }
}
