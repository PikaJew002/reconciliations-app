<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\ProductMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_walmart_item_with_sku_creates_and_reuses_product(): void
    {
        $user = User::factory()->create();
        $merchant = $this->walmartMerchant($user);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $firstOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
        ]);
        $firstItem = OrderItem::factory()->create([
            'order_id' => $firstOrder->id,
            'product_id' => null,
            'line_number' => 1,
            'sku' => '12345',
            'description' => 'Great Value Milk',
            'normalized_description' => 'great value milk',
        ]);

        $secondOrder = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'order_number' => 'ORD-2',
        ]);
        $secondItem = OrderItem::factory()->create([
            'order_id' => $secondOrder->id,
            'product_id' => null,
            'line_number' => 1,
            'sku' => '12345',
            'description' => 'GV Milk Gallon',
            'normalized_description' => 'gv milk gallon',
        ]);

        $result = app(ProductMatchingService::class)->matchForUser($user->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['linked']);

        $firstItem->refresh();
        $secondItem->refresh();

        $this->assertNotNull($firstItem->product_id);
        $this->assertSame($firstItem->product_id, $secondItem->product_id);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'id' => $firstItem->product_id,
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'sku' => '12345',
            'name' => 'Great Value Milk',
            'category_id' => null,
        ]);
    }

    public function test_walmart_item_without_sku_matches_on_normalized_description(): void
    {
        $user = User::factory()->create();
        $merchant = $this->walmartMerchant($user);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
        ]);

        $firstItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'sku' => null,
            'description' => 'Bananas',
            'normalized_description' => 'bananas',
        ]);
        $secondItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 2,
            'sku' => null,
            'description' => 'Bananas',
            'normalized_description' => 'bananas',
        ]);

        $result = app(ProductMatchingService::class)->matchForUser($user->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame($firstItem->fresh()->product_id, $secondItem->fresh()->product_id);
    }

    public function test_amazon_items_are_never_linked_to_products(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
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
            'line_number' => 1,
            'sku' => 'B00AMAZON',
            'description' => 'One-off gadget',
            'normalized_description' => 'one-off gadget',
        ]);

        $result = app(ProductMatchingService::class)->matchForUser($user->id);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['linked']);
        $this->assertNull($item->fresh()->product_id);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_categorized_product_copies_category_onto_new_components(): void
    {
        $user = User::factory()->create();
        $merchant = $this->walmartMerchant($user);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $product = Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'name' => 'Eggs',
            'normalized_name' => 'eggs',
            'sku' => '999',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'tax' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'sku' => '999',
            'description' => 'Eggs',
            'normalized_description' => 'eggs',
            'extended_price' => 4.50,
        ]);

        app(OrderComponentGenerator::class)->generateForOrder($order);

        $component = OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('type', 'product')
            ->first();

        $this->assertNotNull($component);
        $this->assertSame($category->id, $component->category_id);
        $this->assertSame('100.00', $component->category_confidence);
    }

    private function walmartMerchant(User $user): Merchant
    {
        return Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
    }
}
