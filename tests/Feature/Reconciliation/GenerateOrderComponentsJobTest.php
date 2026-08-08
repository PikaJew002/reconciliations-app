<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\GenerateOrderComponents;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Reconciliation\OrderComponentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateOrderComponentsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_components_for_amazon_order_batches(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'subtotal' => 6.97,
            'tax' => 0.42,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
            'total' => 7.39,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Carabiner',
            'extended_price' => 6.97,
            'unit_price' => 6.97,
            'quantity' => 1,
        ]);

        (new GenerateOrderComponents($batch))->handle(app(OrderComponentGenerator::class));

        $this->assertSame(2, OrderComponent::query()->where('order_id', $order->id)->count());
    }

    public function test_skips_non_order_batches(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
        ]);

        (new GenerateOrderComponents($batch))->handle(app(OrderComponentGenerator::class));

        $this->assertSame(0, OrderComponent::query()->count());
    }
}
