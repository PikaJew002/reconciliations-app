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

class WalmartOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_walmart_imports(): void
    {
        $this->get(route('orders.imports.index', 'walmart'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_walmart_imports_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('orders.imports.index', 'walmart'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Imports')
                ->where('merchant.normalized_name', 'walmart')
                ->where('merchant.name', 'Walmart')
                ->has('batches', 0));
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

        $response = $this->actingAs($user)->post(route('orders.imports.store', 'walmart'), [
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

        $response->assertRedirect(route('orders.imports.show', ['walmart', $batch]));
    }

    public function test_authenticated_user_can_view_walmart_import_batch(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'original_filename' => 'walmart.json',
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.show', ['walmart', $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('batch.id', $batch->id)
                ->where('breadcrumbs.0.label', 'Orders')
                ->where('breadcrumbs.1.label', 'Walmart')
                ->where('breadcrumbs.1.href', route('orders.show', 'walmart'))
                ->where('breadcrumbs.2.label', 'Imports')
                ->where('breadcrumbs.2.href', route('orders.imports.index', 'walmart'))
                ->where('breadcrumbs.3.label', 'Import batch'));
    }

    public function test_walmart_imports_lists_only_walmart_batches(): void
    {
        $user = User::factory()->create();

        $walmartBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'original_filename' => 'walmart.json',
        ]);

        ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => 'amazon-scrape.json',
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.index', 'walmart'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Imports')
                ->has('batches', 1)
                ->where('batches.0.id', $walmartBatch->id)
                ->where('batches.0.original_filename', 'walmart.json'));
    }
}
