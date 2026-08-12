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

class AmazonOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_amazon_imports(): void
    {
        $this->get(route('orders.imports.index', 'amazon'))
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_amazon_imports_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('orders.imports.index', 'amazon'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Imports')
                ->where('merchant.normalized_name', 'amazon')
                ->where('merchant.name', 'Amazon')
                ->has('batches', 0));
    }

    public function test_authenticated_user_can_queue_an_amazon_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $summary = UploadedFile::fake()->createWithContent(
            'amazon_order_history_order_summary.csv',
            "order id,date,total,shipping,gift,tax,payments\n".
            "114-0885735-8288246,8/7/26,7.39,0,,0.42,Mastercard ending in 2525: 2026-08-07: \$7.39;\n",
        );

        $items = UploadedFile::fake()->createWithContent(
            'amazon_order_history_item_details.csv',
            "order id,quantity,description,price,ASIN\n".
            "114-0885735-8288246,1,Carabiner,\$6.97 ,B0B6R34RD4\n",
        );

        $response = $this->actingAs($user)->post(route('orders.imports.store', 'amazon'), [
            'summary_file' => $summary,
            'items_file' => $items,
        ]);

        $batch = ImportBatch::query()->first();

        $this->assertNotNull($batch);
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame('amazon', $batch->source);
        $this->assertSame('orders', $batch->type);
        $this->assertSame('pending', $batch->status);
        $this->assertStringEndsWith('/summary.csv', $batch->storage_path);
        $this->assertStringEndsWith('/items.csv', $batch->metadata['items_path']);
        Storage::disk('local')->assertExists($batch->storage_path);
        Storage::disk('local')->assertExists($batch->metadata['items_path']);

        Queue::assertPushed(ProcessImportBatch::class, function (ProcessImportBatch $job) use ($batch) {
            return $job->importBatch->is($batch);
        });

        $response->assertRedirect(route('imports.show', $batch));
    }

    public function test_both_csv_files_are_required(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();

        $summary = UploadedFile::fake()->createWithContent(
            'summary.csv',
            "order id,date,total,gift,tax,payments\n",
        );

        $this->actingAs($user)->post(route('orders.imports.store', 'amazon'), [
            'summary_file' => $summary,
        ])->assertSessionHasErrors('items_file');

        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }
}
