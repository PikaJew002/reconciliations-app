<?php

namespace Tests\Feature\Imports;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_amazon_imports_lists_created_at(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => 'amazon-scrape-2026-08-21-223841.json',
            'created_at' => '2026-08-21 22:38:41',
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.index', 'amazon'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Imports')
                ->has('batches', 1)
                ->where('batches.0.id', $batch->id)
                ->where('batches.0.original_filename', 'amazon-scrape-2026-08-21-223841.json')
                ->where('batches.0.created_at', $batch->created_at->toJSON()));
    }

    public function test_amazon_csv_upload_route_is_gone(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/orders/amazon/imports')
            ->assertNotFound();

        $this->assertSame(0, ImportBatch::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_authenticated_user_can_view_amazon_import_batch(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => 'amazon-scrape-2026-08-21-223841.json',
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.show', ['amazon', $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('batch.id', $batch->id)
                ->where('batch.original_filename', 'amazon-scrape-2026-08-21-223841.json')
                ->where('batch.created_at', $batch->created_at->toJSON())
                ->where('breadcrumbs.0.label', 'Orders')
                ->where('breadcrumbs.1.label', 'Amazon')
                ->where('breadcrumbs.1.href', route('orders.show', 'amazon'))
                ->where('breadcrumbs.2.label', 'Imports')
                ->where('breadcrumbs.2.href', route('orders.imports.index', 'amazon'))
                ->where('breadcrumbs.3.label', 'Import batch')
                ->where('can_revert', true)
                ->where('revert_url', route('orders.imports.destroy', ['amazon', $batch])));
    }

    public function test_amazon_import_batch_shows_date_range_and_orders_list(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => 'orders.json',
        ]);

        $order1 = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'order_number' => '111-0000001-0000001',
            'ordered_at' => '2026-07-10 12:00:00',
            'total' => 25.00,
        ]);

        $order2 = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'order_number' => '111-0000002-0000002',
            'ordered_at' => '2026-07-20 15:30:00',
            'total' => 50.00,
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.show', ['amazon', $batch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('batch.id', $batch->id)
                ->where('date_range.min', '2026-07-10')
                ->where('date_range.max', '2026-07-20')
                ->where('date_range.span_days', 10)
                ->has('orders', 2)
                ->where('orders.0.id', $order2->id)
                ->where('orders.0.order_number', '111-0000002-0000002')
                ->where('orders.0.detail_url', route('orders.detail', ['amazon', $order2->id]))
                ->where('orders.1.id', $order1->id)
                ->where('orders.1.order_number', '111-0000001-0000001')
                ->where('orders.1.detail_url', route('orders.detail', ['amazon', $order1->id]))
                ->where('pagination.total', 2));
    }

    public function test_amazon_import_batch_show_rejects_walmart_batches(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
        ]);

        $this->actingAs($user)
            ->get(route('orders.imports.show', ['amazon', $batch]))
            ->assertNotFound();
    }
}
