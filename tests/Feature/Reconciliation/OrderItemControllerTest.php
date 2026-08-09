<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\TransactionAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderItemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_item_quantity_and_sync_product_component(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'total' => 10.00,
            'status' => 'imported',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
            'unit_price' => 5.00,
            'extended_price' => 10.00,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => $item->description,
            'amount' => 10.00,
            'is_user_modified' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.items.update', [$order, $item]), [
                'quantity' => 1,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'quantity' => 1,
            'extended_price' => 5.00,
        ]);

        $this->assertDatabaseHas('order_components', [
            'id' => $component->id,
            'amount' => 5.00,
            'is_user_modified' => true,
        ]);
    }

    public function test_cannot_update_allocated_item_quantity(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
            'unit_price' => 5.00,
            'extended_price' => 10.00,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'amount' => 10.00,
        ]);

        TransactionAllocation::factory()->create([
            'order_component_id' => $component->id,
            'allocated_amount' => 10.00,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.items.update', [$order, $item]), [
                'quantity' => 1,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'quantity' => 2,
            'extended_price' => 10.00,
        ]);
    }

    public function test_cannot_update_another_users_order_item(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $other->id]);
        $order = Order::factory()->create([
            'user_id' => $other->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
            'unit_price' => 5.00,
            'extended_price' => 10.00,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.items.update', [$order, $item]), [
                'quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_cannot_update_item_on_reconciled_order(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'reconciled',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 2,
            'unit_price' => 5.00,
            'extended_price' => 10.00,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.items.update', [$order, $item]), [
                'quantity' => 1,
            ])
            ->assertStatus(422);
    }
}
