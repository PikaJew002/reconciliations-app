<?php

namespace Tests\Feature\Imports;

use App\Jobs\ClassifyIncome;
use App\Jobs\GenerateOrderComponents;
use App\Jobs\MatchMerchants;
use App\Jobs\PairCreditCardPayments;
use App\Jobs\PairTransfers;
use App\Jobs\ProcessImportBatch;
use App\Jobs\ReconcileSyntheticBankSpend;
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
use Tests\TestCase;

class BankTransactionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_bank_import_create(): void
    {
        $this->get(route('imports.bank-transactions.create'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_queue_a_bank_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create();

        $file = UploadedFile::fake()->createWithContent(
            'chase.csv',
            "Date,Description,Amount\n01/01/2026,WALMART,-12.34\n",
        );

        $response = $this->actingAs($user)->post(route('imports.bank-transactions.store'), [
            'account_id' => $account->id,
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('bank', $batch->source);
        $this->assertSame('transactions', $batch->type);
        $this->assertSame('pending', $batch->status);
        $this->assertSame($account->id, $batch->metadata['account_id']);
        Storage::disk('local')->assertExists($batch->storage_path);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response->assertRedirect(route('imports.show', $batch));
    }

    public function test_bank_import_chains_transfer_pairing_and_income_classification(): void
    {
        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create([
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
            ClassifyIncome::class,
            GenerateOrderComponents::class,
            MatchMerchants::class,
            RunReconciliation::class,
            ReconcileSyntheticBankSpend::class,
        ]);
    }

    public function test_bank_import_pairs_transfers_across_checking_accounts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $accountA = Account::factory()->create([
            'name' => 'Joint Account 2',
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
        ]);
        $accountB = Account::factory()->create([
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
