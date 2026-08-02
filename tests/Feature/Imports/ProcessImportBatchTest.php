<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessImportBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_batch_completed_when_mapping_is_stubbed(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create();
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
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(0, $batch->record_count);
        $this->assertNotNull($batch->completed_at);
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
