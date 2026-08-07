<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessImportBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_cumberland_valley_bank_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $path = 'imports/test.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,4/30/26,TRANSFER FROM X1758 TO X6218  LEFTOVER 4-30-26,,Credit,213.11
Joint Account 2,4/30/26,POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA         KY C#2195,,Debit,6.75
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->record_count);
        $this->assertNotNull($batch->completed_at);

        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertCount(2, $transactions);
        $this->assertSame('2026-04-30', $transactions[0]->posted_at->toDateString());
        $this->assertSame('213.11', $transactions[0]->amount);
        $this->assertNull($transactions[0]->transaction_date);
        $this->assertNotEmpty($transactions[0]->external_id);
        $this->assertSame('2026-04-30', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-04-29', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('-6.75', $transactions[1]->amount);
        $this->assertNotEmpty($transactions[1]->external_id);
        $this->assertNotSame($transactions[0]->external_id, $transactions[1]->external_id);
    }

    public function test_job_skips_duplicate_bank_transactions_on_reimport(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $csv = <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,4/30/26,TRANSFER FROM X1758 TO X6218  LEFTOVER 4-30-26,,Credit,213.11
CSV;

        $firstPath = 'imports/first.csv';
        $secondPath = 'imports/second.csv';
        Storage::disk('local')->put($firstPath, $csv);
        Storage::disk('local')->put($secondPath, $csv);

        $firstBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $firstPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        $secondBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $secondPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        $resolver = app(\App\Services\Imports\ImporterResolver::class);

        (new ProcessImportBatch($firstBatch))->handle($resolver);
        (new ProcessImportBatch($secondBatch))->handle($resolver);

        $firstBatch->refresh();
        $secondBatch->refresh();

        $this->assertSame('completed', $firstBatch->status);
        $this->assertSame(1, $firstBatch->record_count);
        $this->assertSame('completed', $secondBatch->status);
        $this->assertSame(0, $secondBatch->record_count);
        $this->assertSame(1, BankTransaction::query()->count());
    }

    public function test_job_marks_batch_failed_when_account_id_is_missing(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/test.csv';

        Storage::disk('local')->put($path, "Date,Description,Amount\n01/01/2026,WALMART,-12.34\n");

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->error_message);
    }
}
