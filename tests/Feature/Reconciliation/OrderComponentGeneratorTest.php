<?php

namespace Tests\Feature\Reconciliation;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Reconciliation\OrderComponentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderComponentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_product_and_order_level_components(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'subtotal' => 50.00,
            'tax' => 2.50,
            'delivery_fee' => 7.95,
            'tip' => 5.00,
            'discount' => 3.00,
            'total' => 62.45,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Milk',
            'extended_price' => 30.00,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 2,
            'description' => 'Eggs',
            'extended_price' => 20.00,
        ]);

        $generator = app(OrderComponentGenerator::class);

        $this->assertTrue($generator->generateForOrder($order));
        $this->assertFalse($generator->generateForOrder($order->fresh()));

        $components = OrderComponent::query()->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(6, $components);
        $this->assertSame(['product', 'product', 'tax', 'delivery', 'tip', 'discount'], $components->pluck('type')->all());
        $this->assertSame('30.00', $components[0]->amount);
        $this->assertSame('20.00', $components[1]->amount);
        $this->assertSame('2.50', $components[2]->amount);
        $this->assertSame('7.95', $components[3]->amount);
        $this->assertSame('5.00', $components[4]->amount);
        $this->assertSame('-3.00', $components[5]->amount);
    }

    public function test_skips_zero_amount_order_level_components(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'tax' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'extended_price' => 10.00,
        ]);

        app(OrderComponentGenerator::class)->generateForOrder($order);

        $this->assertCount(1, OrderComponent::query()->where('order_id', $order->id)->get());
    }
}
