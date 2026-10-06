<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Category;
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
            ->assertRedirect(route('reconciliation.needs-review'))
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
            ->assertRedirect(route('reconciliation.needs-review'));

        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
    }

    public function test_deleting_allocated_component_unwinds_match_and_reopens_order(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'reconciled',
            'total' => 10.00,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 5.00,
            'order_item_id' => null,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'amount' => 5.00,
            'order_item_id' => null,
        ]);

        $allocation = TransactionAllocation::factory()->create([
            'order_component_id' => $component->id,
            'allocated_amount' => 5.00,
        ]);
        $allocation->bankTransaction->update(['status' => 'matched']);

        $this->actingAs($user)
            ->from(route('reconciliation.needs-review'))
            ->delete(route('reconciliation.orders.components.destroy', [$order, $component]))
            ->assertRedirect(route('reconciliation.needs-review'));

        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
        $this->assertDatabaseMissing('transaction_allocations', ['id' => $allocation->id]);
        $this->assertSame('imported', $order->fresh()->status);
        $this->assertSame('unmatched', $allocation->bankTransaction->fresh()->status);
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

    public function test_deleting_a_grouped_row_removes_only_the_selected_components(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $first = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Seasoning',
            'amount' => 0.77,
        ]);
        $second = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Seasoning',
            'amount' => 0.77,
        ]);
        $kept = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Milk',
            'amount' => 4,
        ]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.destroy', [$order, $first]), [
                'component_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('order_components', ['id' => $first->id]);
        $this->assertDatabaseMissing('order_components', ['id' => $second->id]);
        $this->assertDatabaseHas('order_components', ['id' => $kept->id]);
    }

    public function test_grouped_delete_rejects_a_component_from_another_order(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $other = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $first = OrderComponent::factory()->create(['order_id' => $order->id]);
        $foreign = OrderComponent::factory()->create(['order_id' => $other->id]);

        $this->actingAs($user)
            ->delete(route('reconciliation.orders.components.destroy', [$order, $first]), [
                'component_ids' => [$first->id, $foreign->id],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('order_components', ['id' => $first->id]);
        $this->assertDatabaseHas('order_components', ['id' => $foreign->id]);
    }

    public function test_category_save_can_update_every_component_in_a_grouped_row(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->expense()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => 'imported',
        ]);
        $first = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Seasoning',
            'amount' => 0.77,
            'category_id' => null,
        ]);
        $second = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Seasoning',
            'amount' => 0.77,
            'category_id' => null,
        ]);
        $milk = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'type' => 'product',
            'description' => 'Milk',
            'amount' => 4,
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('reconciliation.orders.components.category.update', [$order, $first]), [
                'category_id' => $category->id,
                'component_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertSame($category->id, $first->fresh()->category_id);
        $this->assertSame($category->id, $second->fresh()->category_id);
        $this->assertNull($milk->fresh()->category_id);
    }
}
