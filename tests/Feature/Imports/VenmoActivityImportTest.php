<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VenmoActivityImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_venmo_imports(): void
    {
        $this->get(route('venmo.imports.index'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_venmo_imports_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('venmo.imports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Venmo/Imports')
                ->has('batches', 0));
    }

    public function test_authenticated_user_can_queue_a_venmo_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent(
            'Jun-2026-statement.csv',
            $this->venmoCsv(),
        );

        $response = $this->actingAs($user)->post(route('venmo.imports.store'), [
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('venmo', $batch->source);
        $this->assertSame('activity', $batch->type);
        $this->assertSame('pending', $batch->status);
        Storage::disk('local')->assertExists($batch->storage_path);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response->assertRedirect(route('venmo.imports.show', $batch));
    }

    public function test_authenticated_user_can_view_venmo_import_batch(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'venmo',
            'type' => 'activity',
            'original_filename' => 'venmo.csv',
        ]);

        $this->actingAs($user)
            ->get(route('venmo.imports.show', $batch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('batch.id', $batch->id)
                ->where('breadcrumbs.0.label', 'Accounts')
                ->where('breadcrumbs.1.label', 'Venmo')
                ->where('breadcrumbs.1.href', route('venmo.imports.index'))
                ->where('breadcrumbs.2.label', 'Import batch'));
    }

    public function test_venmo_import_batch_show_rejects_bank_batches(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
        ]);

        $this->actingAs($user)
            ->get(route('venmo.imports.show', $batch))
            ->assertNotFound();
    }

    public function test_csv_file_is_required(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('venmo.imports.store'), [])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    protected function venmoCsv(): string
    {
        return <<<'CSV'
Account Statement - (@Aaron-Eisenberg-7) ,,,,,,,,,,,,,,,,,,,,,
Account Activity,,,,,,,,,,,,,,,,,,,,,
,ID,Datetime,Type,Status,Note,From,To,Amount (total),Amount (tip),Amount (tax),Amount (fee),Tax Rate,Tax Exempt,Funding Source,Destination,Beginning Balance,Ending Balance,Statement Period Venmo Fees,Terminal Location,Year to Date Venmo Fees,Disclaimer
,4613052433140029613,2026-06-05T19:11:43,Payment,Complete,Extreme,Aaron Eisenberg,Tyler Adams,- $250.00,,0,,0,,Mastercard *2195,,,,,Venmo,,
CSV;
    }
}
