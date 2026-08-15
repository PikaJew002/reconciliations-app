<?php

namespace Tests\Feature\Imports;

use App\Jobs\CategorizeTransactions;
use App\Jobs\GenerateOrderComponents;
use App\Jobs\MatchMerchants;
use App\Jobs\MatchPlannedOccurrences;
use App\Jobs\PairCreditCardPayments;
use App\Jobs\PairTransfers;
use App\Jobs\ProcessImportBatch;
use App\Jobs\RunReconciliation;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Imports\ImporterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankTransactionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_account_imports(): void
    {
        $account = Account::factory()->create();

        $this->get(route('accounts.imports.index', $account))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_account_imports_page(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
        ]);

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Imports')
                ->where('account.id', $account->id)
                ->where('account.name', 'Checking')
                ->has('batches', 0)
                ->missing('accounts'));
    }

    public function test_authenticated_user_cannot_import_into_other_users_accounts(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $account))
            ->assertForbidden();

        $this->actingAs($user)->post(route('accounts.imports.store', $account), [
            'file' => UploadedFile::fake()->createWithContent(
                'chase.csv',
                "Date,Description,Amount\n01/01/2026,WALMART,-12.34\n",
            ),
        ])->assertForbidden();

        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_authenticated_user_can_queue_a_bank_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $file = UploadedFile::fake()->createWithContent(
            'chase.csv',
            "Date,Description,Amount\n01/01/2026,WALMART,-12.34\n",
        );

        $response = $this->actingAs($user)->post(route('accounts.imports.store', $account), [
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('bank', $batch->source);
        $this->assertSame('transactions', $batch->type);
        $this->assertSame('pending', $batch->status);
        $this->assertSame((string) $account->id, $batch->metadata['account_id']);
        Storage::disk('local')->assertExists($batch->storage_path);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response->assertRedirect(route('accounts.imports.show', [$account, $batch]));
    }

    public function test_authenticated_user_can_view_account_import_batch(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'chase.csv',
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        $this->actingAs($user)
            ->get(route('accounts.imports.show', [$account, $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('batch.id', $batch->id)
                ->where('batch.original_filename', 'chase.csv')
                ->where('breadcrumbs.0.label', 'Accounts')
                ->where('breadcrumbs.0.href', route('accounts.index'))
                ->where('breadcrumbs.1.label', 'Checking')
                ->where('breadcrumbs.1.href', route('accounts.show', $account))
                ->where('breadcrumbs.2.label', 'Imports')
                ->where('breadcrumbs.2.href', route('accounts.imports.index', $account))
                ->where('breadcrumbs.3.label', 'Import batch')
                ->missing('breadcrumbs.3.href'));
    }

    public function test_account_import_batch_show_rejects_batches_for_other_accounts(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id]);
        $accountB = Account::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'metadata' => ['account_id' => (string) $accountB->id],
        ]);

        $this->actingAs($user)
            ->get(route('accounts.imports.show', [$accountA, $batch]))
            ->assertNotFound();
    }

    public function test_account_imports_lists_only_batches_for_that_account(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Account A',
        ]);
        $accountB = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Account B',
        ]);

        $batchA = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'a.csv',
            'metadata' => ['account_id' => (string) $accountA->id],
        ]);

        ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'b.csv',
            'metadata' => ['account_id' => (string) $accountB->id],
        ]);

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $accountA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Imports')
                ->has('batches', 1)
                ->where('batches.0.id', $batchA->id)
                ->where('batches.0.original_filename', 'a.csv'));
    }

    public function test_bank_import_chains_transfer_pairing_and_income_classification(): void
    {
        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
        ]);
        $path = 'imports/chain-transfers.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,2026-07-22,"DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525",,Debit,12.25
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        Bus::assertChained([
            PairCreditCardPayments::class,
            PairTransfers::class,
            CategorizeTransactions::class,
            GenerateOrderComponents::class,
            MatchMerchants::class,
            MatchPlannedOccurrences::class,
            RunReconciliation::class,
        ]);
    }

    public function test_bank_import_pairs_transfers_across_checking_accounts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $accountA = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Joint Account 2',
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
        ]);
        $accountB = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Joint Account 1',
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'last_four' => '1758',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $accountA->id,
            'amount' => -82.62,
            'posted_at' => '2026-08-06',
            'description' => 'TRANSFER FROM X6218 TO X1758 AMAZON',
            'normalized_description' => 'transfer from x6218 to x1758 amazon',
            'status' => 'unmatched',
        ]);

        $path = 'imports/transfer-credit.csv';
        Storage::disk('local')->put($path, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 1,2026-08-06,"TRANSFER FROM X6218 TO X1758 AMAZON",,Credit,82.62
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => $accountB->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $this->assertDatabaseHas('bank_transactions', [
            'account_id' => $accountA->id,
            'amount' => -82.62,
            'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
            'status' => 'ignored',
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'account_id' => $accountB->id,
            'amount' => 82.62,
            'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
            'status' => 'ignored',
        ]);
    }
}
