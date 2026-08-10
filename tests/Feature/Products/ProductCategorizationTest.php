<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProductCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_uncategorized_products(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $expense = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        Category::factory()->for($user)->bill()->create(['name' => 'Rent']);

        Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => null,
            'name' => 'Milk',
            'normalized_name' => 'milk',
            'sku' => '111',
        ]);
        Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => $expense->id,
            'name' => 'Already categorized',
            'normalized_name' => 'already categorized',
            'sku' => '222',
        ]);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Products/Index')
                ->has('products', 1)
                ->where('products.0.name', 'Milk')
                ->has('categories', 1)
                ->where('categories.0.name', 'Groceries'));
    }

    public function test_user_can_categorize_product_and_backfill_null_components(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => null,
            'name' => 'Bread',
            'normalized_name' => 'bread',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $uncategorized = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => null,
        ]);
        $alreadySet = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'category_id' => Category::factory()->for($user)->expense()->create()->id,
            'is_user_modified' => true,
        ]);
        $previousCategoryId = $alreadySet->category_id;

        $this->actingAs($user)
            ->patch(route('products.category.update', $product), [
                'category_id' => $category->id,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertSame($category->id, $uncategorized->fresh()->category_id);
        $this->assertSame($previousCategoryId, $alreadySet->fresh()->category_id);
    }

    public function test_manual_product_reconciliation_matches_walmart_items(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'sku' => '555',
            'description' => 'Apples',
            'normalized_description' => 'apples',
        ]);

        $this->actingAs($user)
            ->post(route('products.reconcile'))
            ->assertRedirect(route('products.index'));

        $this->assertNotNull($item->fresh()->product_id);
        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'sku' => '555',
            'category_id' => null,
        ]);
    }
}
