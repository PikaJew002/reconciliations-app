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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_categorize(): void
    {
        $this->get(route('orders.categorize'))
            ->assertRedirect('/login');
    }

    public function test_queue_lists_dirty_walmart_and_amazon_orders_only(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
        ]);

        $uncategorizedProduct = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'name' => 'Milk',
            'normalized_name' => 'milk',
            'sku' => '111',
        ]);
        $categorizedProduct = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => $category->id,
            'name' => 'Bread',
            'normalized_name' => 'bread',
            'sku' => '222',
        ]);

        $dirtyWalmart = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-DIRTY',
            'ordered_at' => '2026-08-02 00:00:00',
            'total' => 12.00,
        ]);
        OrderItem::factory()->create([
            'order_id' => $dirtyWalmart->id,
            'product_id' => $uncategorizedProduct->id,
            'description' => 'Milk gallon',
            'sku' => '111',
        ]);

        $unlinkedWalmart = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-UNLINKED',
            'ordered_at' => '2026-08-01 00:00:00',
            'total' => 5.00,
        ]);
        OrderItem::factory()->create([
            'order_id' => $unlinkedWalmart->id,
            'product_id' => null,
            'description' => 'Mystery item',
            'sku' => '999',
        ]);

        $cleanWalmart = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-CLEAN',
            'ordered_at' => '2026-07-30 00:00:00',
            'total' => 8.00,
        ]);
        OrderItem::factory()->create([
            'order_id' => $cleanWalmart->id,
            'product_id' => $categorizedProduct->id,
            'description' => 'Bread loaf',
            'sku' => '222',
        ]);

        $dirtyAmazon = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'A-DIRTY',
            'ordered_at' => '2026-08-03 00:00:00',
            'total' => 20.00,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $dirtyAmazon->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'USB cable',
            'amount' => 12.00,
            'category_id' => null,
        ]);

        $cleanAmazon = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'A-CLEAN',
            'ordered_at' => '2026-07-29 00:00:00',
            'total' => 15.00,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $cleanAmazon->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Already done',
            'amount' => 15.00,
            'category_id' => $category->id,
        ]);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 3)
                ->has('categories', 1)
                ->where('orders.0.order_number', 'A-DIRTY')
                ->where('orders.0.mode', 'components')
                ->where('orders.0.lines.0.kind', 'component')
                ->where('orders.0.lines.0.description', 'USB cable')
                ->where('orders.1.order_number', 'W-DIRTY')
                ->where('orders.1.mode', 'items')
                ->where('orders.1.lines.0.status', 'needs_category')
                ->where('orders.1.lines.0.product.id', $uncategorizedProduct->id)
                ->where('orders.2.order_number', 'W-UNLINKED')
                ->where('orders.2.lines.0.status', 'needs_product'));
    }

    public function test_categorizing_product_removes_sibling_orders_from_queue(): void
    {
        $user = User::factory()->create();
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
            'name' => 'Eggs',
            'normalized_name' => 'eggs',
            'sku' => 'E1',
        ]);

        $first = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-1',
        ]);
        $second = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-2',
        ]);

        foreach ([$first, $second] as $order) {
            $item = OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
            ]);
            OrderComponent::factory()->create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'product',
                'category_id' => null,
            ]);
        }

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->patch(route('products.category.update', $product), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($category->id, $product->fresh()->category_id);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 0));
    }

    public function test_create_and_categorize_links_walmart_item_to_new_product(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Household']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku' => 'SOAP-1',
            'description' => 'Dish soap',
            'normalized_description' => 'dish soap',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.items.categorize-as-product', $item), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $item->refresh();
        $this->assertNotNull($item->product_id);
        $this->assertSame($category->id, $item->product->category_id);
        $this->assertSame($category->id, $component->fresh()->category_id);
        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'sku' => 'SOAP-1',
            'category_id' => $category->id,
        ]);
    }

    public function test_create_and_categorize_links_existing_product_by_sku(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'sku' => 'EXISTING',
            'name' => 'Existing soap',
            'normalized_name' => 'existing soap',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku' => 'EXISTING',
            'description' => 'Soap refill',
            'normalized_description' => 'soap refill',
        ]);

        $this->actingAs($user)
            ->post(route('orders.items.categorize-as-product', $item), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($product->id, $item->fresh()->product_id);
        $this->assertSame($category->id, $product->fresh()->category_id);
    }

    public function test_amazon_component_categorization_is_one_off(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'description' => 'Kindle case',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->patch(route('reconciliation.orders.components.category.update', [$order, $component]), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($category->id, $component->fresh()->category_id);
        $this->assertDatabaseCount('products', 0);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
    }

    public function test_cannot_categorize_as_product_for_amazon_item(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('orders.items.categorize-as-product', $item), [
                'category_id' => $category->id,
            ])
            ->assertNotFound();
    }

    public function test_removing_item_deletes_components_item_and_orphan_product(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'name' => 'Apple Music ad',
            'normalized_name' => 'apple music ad',
            'sku' => 'AD-1',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'status' => 'imported',
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Apple Music ad',
            'extended_price' => 0,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
            'amount' => 0,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->delete(route('orders.items.destroy', $item))
            ->assertRedirect(route('orders.categorize'));

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('order_components', ['id' => $component->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_removing_item_keeps_product_when_other_items_still_link(): void
    {
        $user = User::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
        ]);
        $firstOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'status' => 'imported',
        ]);
        $secondOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'status' => 'imported',
        ]);
        $firstItem = OrderItem::factory()->create([
            'order_id' => $firstOrder->id,
            'product_id' => $product->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $secondOrder->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($user)
            ->delete(route('orders.items.destroy', $firstItem))
            ->assertRedirect(route('orders.categorize'));

        $this->assertDatabaseMissing('order_items', ['id' => $firstItem->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_categorize_all_applies_category_to_walmart_order_lines(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
        ]);
        $productA = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'sku' => 'A1',
            'normalized_name' => 'milk',
        ]);
        $productB = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'sku' => 'B1',
            'normalized_name' => 'eggs',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'line_number' => 1,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $productB->id,
            'line_number' => 2,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 3,
            'sku' => 'C1',
            'description' => 'Butter',
            'normalized_description' => 'butter',
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.categorize-all', $order), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($category->id, $productA->fresh()->category_id);
        $this->assertSame($category->id, $productB->fresh()->category_id);
        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'sku' => 'C1',
            'category_id' => $category->id,
        ]);
        $this->assertNotNull($order->items()->where('line_number', 3)->first()?->product_id);
    }

    public function test_categorize_all_applies_category_to_amazon_components(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
        ]);
        $first = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'category_id' => null,
            'description' => 'Item A',
        ]);
        $second = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'category_id' => null,
            'description' => 'Item B',
        ]);
        $already = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'product',
            'category_id' => Category::factory()->for($user)->expense()->create()->id,
            'description' => 'Done',
        ]);
        $previous = $already->category_id;

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.categorize-all', $order), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertSame($category->id, $first->fresh()->category_id);
        $this->assertSame($category->id, $second->fresh()->category_id);
        $this->assertSame($previous, $already->fresh()->category_id);
    }

    public function test_queue_hides_one_off_walmart_line_but_keeps_same_product_elsewhere(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Gifts']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'name' => 'LEGO set',
            'normalized_name' => 'lego set',
            'sku' => 'LEGO-1',
        ]);

        $giftOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-GIFT',
            'ordered_at' => '2026-08-10 00:00:00',
        ]);
        $giftItem = OrderItem::factory()->create([
            'order_id' => $giftOrder->id,
            'product_id' => $product->id,
            'description' => 'LEGO set',
            'sku' => 'LEGO-1',
        ]);
        OrderComponent::factory()->create([
            'order_id' => $giftOrder->id,
            'order_item_id' => $giftItem->id,
            'type' => 'product',
            'category_id' => $category->id,
            'is_user_modified' => true,
        ]);

        $laterOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-LATER',
            'ordered_at' => '2026-08-11 00:00:00',
        ]);
        $laterItem = OrderItem::factory()->create([
            'order_id' => $laterOrder->id,
            'product_id' => $product->id,
            'description' => 'LEGO set',
            'sku' => 'LEGO-1',
        ]);
        OrderComponent::factory()->create([
            'order_id' => $laterOrder->id,
            'order_item_id' => $laterItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'W-LATER')
                ->where('orders.0.lines.0.id', $laterItem->id)
                ->where('orders.0.lines.0.status', 'needs_category'));
    }

    public function test_this_time_only_categorizes_component_without_sticking_product(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Gifts']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'name' => 'Toy',
            'normalized_name' => 'toy',
            'sku' => 'TOY-1',
        ]);
        $first = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-1',
        ]);
        $second = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-2',
        ]);
        $firstItem = OrderItem::factory()->create([
            'order_id' => $first->id,
            'product_id' => $product->id,
        ]);
        $secondItem = OrderItem::factory()->create([
            'order_id' => $second->id,
            'product_id' => $product->id,
        ]);
        $firstComponent = OrderComponent::factory()->create([
            'order_id' => $first->id,
            'order_item_id' => $firstItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);
        $secondComponent = OrderComponent::factory()->create([
            'order_id' => $second->id,
            'order_item_id' => $secondItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.items.categorize-this-time', $firstItem), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertNull($product->fresh()->category_id);
        $this->assertSame($category->id, $firstComponent->fresh()->category_id);
        $this->assertTrue($firstComponent->fresh()->is_user_modified);
        $this->assertNull($secondComponent->fresh()->category_id);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'W-2')
                ->where('orders.0.lines.0.id', $secondItem->id));
    }

    public function test_this_time_only_links_product_without_categorizing_it(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Household']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku' => 'SOAP-ONCE',
            'description' => 'Dish soap',
            'normalized_description' => 'dish soap',
            'extended_price' => 3.50,
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.items.categorize-this-time', $item), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $item->refresh();
        $this->assertNotNull($item->product_id);
        $this->assertNull($item->product->category_id);
        $this->assertSame($category->id, $component->fresh()->category_id);
        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'sku' => 'SOAP-ONCE',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 0));
    }

    public function test_this_time_only_generates_missing_components_for_this_item_only(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'tax' => 1.10,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'description' => 'Bananas',
            'extended_price' => 2.00,
        ]);

        $this->actingAs($user)
            ->post(route('orders.items.categorize-this-time', $item), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->assertNull($product->fresh()->category_id);
        $this->assertDatabaseHas('order_components', [
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => $category->id,
            'is_user_modified' => true,
        ]);
        $this->assertDatabaseHas('order_components', [
            'order_id' => $order->id,
            'type' => 'tax',
            'category_id' => null,
        ]);
    }

    public function test_this_time_only_leaves_sibling_line_on_same_order(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'category_id' => null,
            'sku' => 'DUP-1',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'W-DUP',
        ]);
        $firstItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'First box',
        ]);
        $secondItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'line_number' => 2,
            'description' => 'Second box',
        ]);
        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $firstItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);
        OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $secondItem->id,
            'type' => 'product',
            'category_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('orders.categorize'))
            ->post(route('orders.items.categorize-this-time', $firstItem), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('orders.categorize'));

        $this->actingAs($user)
            ->get(route('orders.categorize'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Categorize')
                ->has('orders', 1)
                ->where('orders.0.order_number', 'W-DUP')
                ->has('orders.0.lines', 1)
                ->where('orders.0.lines.0.id', $secondItem->id));
    }

    public function test_cannot_categorize_this_time_for_amazon_item(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $amazon = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
        ]);

        $this->actingAs($user)
            ->post(route('orders.items.categorize-this-time', $item), [
                'category_id' => $category->id,
            ])
            ->assertNotFound();
    }
}
