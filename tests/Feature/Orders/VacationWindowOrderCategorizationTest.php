<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\VacationWindow;
use App\Services\Reconciliation\OrderComponentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VacationWindowOrderCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_walmart_component_generation_skips_product_category_inside_window(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => $category->id,
            'name' => 'Milk',
            'normalized_name' => 'milk',
        ]);

        $inside = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $inside->id,
            'product_id' => $product->id,
            'extended_price' => 4.50,
        ]);

        $outside = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-15 12:00:00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $outside->id,
            'product_id' => $product->id,
            'extended_price' => 4.50,
        ]);

        $generator = app(OrderComponentGenerator::class);
        $this->assertTrue($generator->generateForOrder($inside));
        $this->assertTrue($generator->generateForOrder($outside));

        $this->assertNull(
            OrderComponent::query()
                ->where('order_id', $inside->id)
                ->where('type', 'product')
                ->value('category_id'),
        );
        $this->assertSame(
            $category->id,
            OrderComponent::query()
                ->where('order_id', $outside->id)
                ->where('type', 'product')
                ->value('category_id'),
        );
    }

    public function test_product_category_update_does_not_paint_vacation_window_components(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'name' => 'Bread',
            'normalized_name' => 'bread',
        ]);

        $inside = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        $insideItem = OrderItem::factory()->create([
            'order_id' => $inside->id,
            'product_id' => $product->id,
        ]);
        $insideComponent = OrderComponent::factory()->create([
            'order_id' => $inside->id,
            'order_item_id' => $insideItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $outside = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-15 12:00:00',
        ]);
        $outsideItem = OrderItem::factory()->create([
            'order_id' => $outside->id,
            'product_id' => $product->id,
        ]);
        $outsideComponent = OrderComponent::factory()->create([
            'order_id' => $outside->id,
            'order_item_id' => $outsideItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('products.category.update', $product), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertNull($insideComponent->fresh()->category_id);
        $this->assertSame($category->id, $outsideComponent->fresh()->category_id);
    }

    public function test_queue_lists_vacation_walmart_order_even_when_product_already_has_category(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => $category->id,
            'name' => 'Milk',
            'normalized_name' => 'milk',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-VACATION',
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Milk gallon',
        ]);
        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 1)
                ->has('vacation_windows', 1)
                ->where('orders.0.order_number', 'W-VACATION')
                ->where('orders.0.in_vacation_window', true)
                ->where('orders.0.lines.0.id', $item->id)
                ->where('orders.0.lines.0.status', 'needs_category'));
    }

    public function test_walmart_persistent_categorize_all_is_blocked_inside_window(): void
    {
        [$user, $order, $category] = $this->vacationWalmartOrder();

        $this->actingAs($user)
            ->post(route('orders.categorize-all', $order), [
                'category_id' => $category->id,
            ])
            ->assertNotFound();

        $this->assertNull($order->items()->first()?->product?->category_id);
    }

    public function test_walmart_create_as_product_is_blocked_inside_window(): void
    {
        [$user, $order, $category] = $this->vacationWalmartOrder();
        $item = $order->items()->first();

        $this->actingAs($user)
            ->post(route('orders.items.categorize-as-product', $item), [
                'category_id' => $category->id,
            ])
            ->assertNotFound();

        $this->assertNull($item->fresh()->product_id);
    }

    public function test_walmart_this_time_still_works_inside_window_even_when_product_has_category(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $groceries = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $vacation = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => $groceries->id,
            'name' => 'Milk',
            'normalized_name' => 'milk',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.categorize-all-this-time', $order), [
                'category_id' => $vacation->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($groceries->id, $product->fresh()->category_id);
        $this->assertSame($vacation->id, $component->fresh()->category_id);
    }

    public function test_amazon_categorize_all_still_works_inside_window(): void
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.categorize-all', $order), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($category->id, $component->fresh()->category_id);
    }

    /**
     * @return array{0: User, 1: Order, 2: Category}
     */
    protected function vacationWalmartOrder(): array
    {
        $user = User::factory()->create();
        VacationWindow::factory()->create([
            'user_id' => $user->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-10',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Vacation']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-05 12:00:00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku' => 'SOAP-1',
            'description' => 'Dish soap',
            'normalized_description' => 'dish soap',
        ]);

        return [$user, $order->load('items'), $category];
    }
}
