<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
