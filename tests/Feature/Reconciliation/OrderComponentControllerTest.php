<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderComponentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_component_to_unbalanced_order(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'total' => 199.33,
            'status' => 'imported',
        ]);

        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 194.33,
            'order_item_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.orders.components.store', $order), [
                'type' => 'delivery',
                'description' => 'Fast delivery fee',
                'amount' => 5.00,
            ])
            ->assertRedirect(route('reconciliation.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_components', [
            'order_id' => $order->id,
            'type' => 'delivery',
            'description' => 'Fast delivery fee',
            'amount' => 5.00,
            'is_user_modified' => true,
        ]);
    }

    public function test_user_can_delete_unallocated_component(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 5.00,
            'order_item_id' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.destroy', [$order, $component]))
            ->assertRedirect(route('reconciliation.index'));

        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
    }

    public function test_cannot_delete_allocated_component(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 5.00,
            'order_item_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'order_component_id' => $component->id,
            'allocated_amount' => 5.00,
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.destroy', [$order, $component]))
            ->assertStatus(422);

        $this->assertDatabaseHas('order_components', ['id' => $component->id]);
    }

    public function test_cannot_edit_another_users_order(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $other->id]);
        $order = Order::factory()->create([
            'user_id' => $other->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.orders.components.store', $order), [
                'type' => 'fee',
                'description' => 'Nope',
                'amount' => 1,
            ])
            ->assertForbidden();
    }
}
