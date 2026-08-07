<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WalmartOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_walmart_import_create(): void
    {
        $this->get(route('imports.walmart-orders.create'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_queue_a_walmart_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $file = UploadedFile::fake()->createWithContent(
            'walmart-orders.json',
            json_encode([
                [
                    'orderNumber' => '70188890864903553777',
                    'orderDate' => 'Jul 25, 2026 purchase',
                    'orderSubtotal' => '$71.77',
                    'orderTotal' => '$71.98',
                    'tax' => '$0.21',
                    'deliveryCharges' => '$0.00',
                    'tip' => '$0.00',
                    'savings' => '',
                    'items' => [],
                ],
            ]),
        );

        $response = $this->actingAs($user)->post(route('imports.walmart-orders.store'), [
            'file' => $file,
        ]);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('walmart', $batch->source);
        $this->assertSame('orders', $batch->type);
        $this->assertSame('pending', $batch->status);
        $this->assertStringEndsWith('.json', $batch->storage_path);
        Storage::disk('local')->assertExists($batch->storage_path);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response->assertRedirect(route('imports.show', $batch));
    }
}
