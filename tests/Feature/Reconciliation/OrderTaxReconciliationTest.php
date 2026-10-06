<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ComponentTaxRule;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderTaxReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderTaxReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_splits_tax_and_learns_products(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Household']);
        $soap = $this->product($user, $merchant, 'Soap', '111');
        $milk = $this->product($user, $merchant, 'Milk', '222');

        $order = $this->order($user, $merchant, $batch, '14.00', '0.60', '14.60');
        $soapItem = $this->item($order, $soap, 1, '10.00', 'Soap');
        $milkItem = $this->item($order, $milk, 2, '4.00', 'Milk');
        $soapComponent = $this->productComponent($order, $soapItem, $category->id);
        $milkComponent = $this->productComponent($order, $milkItem, null);
        $this->lumpTax($order, '0.60');

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $order), [
                'rate' => '0.06000',
                'component_ids' => [$soapComponent->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('0.06000', $user->fresh()->sales_tax_rate);
        $this->assertTrue($soap->fresh()->is_taxable);
        $this->assertFalse($milk->fresh()->is_taxable);
        $this->assertTrue((bool) $soapItem->fresh()->taxable);
        $this->assertFalse((bool) $milkItem->fresh()->taxable);
        $this->assertDatabaseMissing('order_components', ['id' => $order->components()->where('type', 'tax')->whereNull('order_item_id')->value('id')]);

        $partials = OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('type', 'tax')
            ->get();

        $this->assertCount(1, $partials);
        $this->assertSame($soapItem->id, $partials[0]->order_item_id);
        $this->assertSame($category->id, $partials[0]->category_id);
        $this->assertSame('0.60', $partials[0]->amount);
        $this->assertTrue($partials[0]->metadata['tax_allocation']);
        $this->assertSame(0, $order->components()->where('type', 'tax')->whereNull('order_item_id')->count());
        $this->assertSame(14.6, $order->fresh()->payableComponentSum());
    }

    public function test_detail_prechecks_learned_lines_and_save_reconciles_an_older_order(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111');
        $milk = $this->product($user, $merchant, 'Milk', '222');

        $older = $this->order($user, $merchant, $batch, '14.00', '0.60', '14.60', 'OLDER-1');
        $olderSoap = $this->item($older, $soap, 1, '10.00', 'Soap');
        $olderMilk = $this->item($older, $milk, 2, '4.00', 'Milk');
        $olderSoapComponent = $this->productComponent($older, $olderSoap, null);
        $olderMilkComponent = $this->productComponent($older, $olderMilk, null);
        $this->lumpTax($older, '0.60');

        $current = $this->order($user, $merchant, $batch, '14.00', '0.60', '14.60', 'CURRENT-1');
        $currentSoap = $this->item($current, $soap, 1, '10.00', 'Soap');
        $currentMilk = $this->item($current, $milk, 2, '4.00', 'Milk');
        $currentSoapComponent = $this->productComponent($current, $currentSoap, null);
        $this->productComponent($current, $currentMilk, null);
        $this->lumpTax($current, '0.60');

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $current), [
                'rate' => '0.06000',
                'component_ids' => [$currentSoapComponent->id],
            ])
            ->assertRedirect();

        $this->assertNotNull($older->fresh()->components()->where('type', 'tax')->whereNull('order_item_id')->where('description', 'Sales Tax')->first());

        $this->actingAs($user)
            ->get(route('orders.detail', ['merchant' => 'walmart', 'order' => $older->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tax_reconciliation.tax_cents', 60)
                ->where('tax_reconciliation.rate', '0.06000')
                ->where('tax_reconciliation.lines', function (mixed $lines) use ($olderSoapComponent, $olderMilkComponent): bool {
                    $byId = collect($lines)->keyBy('id');

                    return $byId[$olderSoapComponent->id]['selected'] === true
                        && $byId[$olderSoapComponent->id]['tax_status'] === true
                        && $byId[$olderMilkComponent->id]['selected'] === false
                        && $byId[$olderMilkComponent->id]['tax_status'] === false;
                }));

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $older), [
                'rate' => '0.06000',
                'component_ids' => [$olderSoapComponent->id],
            ])
            ->assertRedirect();

        $this->assertNull(
            $older->fresh()->components()->where('description', 'Sales Tax')->first(),
        );
        $this->assertSame(60, (int) round((float) $older->components()->where('type', 'tax')->sum('amount') * 100));
    }

    public function test_new_order_auto_closes_when_every_line_is_known(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111', true);
        $milk = $this->product($user, $merchant, 'Milk', '222', false);

        $order = $this->order($user, $merchant, $batch, '15.00', '0.60', '15.60');
        $this->item($order, $soap, 1, '10.00', 'Soap');
        $this->item($order, $milk, 2, '5.00', 'Milk');

        $this->assertTrue(app(OrderComponentGenerator::class)->generateForOrder($order));

        $this->assertNull(
            OrderComponent::query()->where('order_id', $order->id)->where('description', 'Sales Tax')->first(),
        );

        $partials = OrderComponent::query()->where('order_id', $order->id)->where('type', 'tax')->get();
        $this->assertCount(1, $partials);
        $this->assertSame('0.60', $partials[0]->amount);
        $this->assertSame('Soap', OrderItem::query()->find($partials[0]->order_item_id)?->description);
    }

    public function test_unknown_line_keeps_the_lump_tax_on_a_new_order(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111', true);

        $order = $this->order($user, $merchant, $batch, '14.00', '0.60', '14.60');
        $this->item($order, $soap, 1, '10.00', 'Soap');
        $this->item($order, null, 2, '4.00', 'New cereal');

        app(OrderComponentGenerator::class)->generateForOrder($order);

        $lump = OrderComponent::query()
            ->where('order_id', $order->id)
            ->where('description', 'Sales Tax')
            ->first();

        $this->assertNotNull($lump);
        $this->assertSame('0.60', $lump->amount);
    }

    public function test_a_later_save_overwrites_the_product_tax_status(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $milk = $this->product($user, $merchant, 'Milk', '222', false);

        $order = $this->order($user, $merchant, $batch, '4.00', '0.24', '4.24');
        $item = $this->item($order, $milk, 1, '4.00', 'Milk');
        $component = $this->productComponent($order, $item, null);
        $this->lumpTax($order, '0.24');

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $order), [
                'rate' => '0.06000',
                'component_ids' => [$component->id],
            ])
            ->assertRedirect();

        $this->assertTrue($milk->fresh()->is_taxable);
    }

    public function test_delivery_rule_is_learned_and_reused(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111', true);

        $order = $this->order($user, $merchant, $batch, '10.00', '0.90', '15.90');
        $item = $this->item($order, $soap, 1, '10.00', 'Soap');
        $component = $this->productComponent($order, $item, null);
        $delivery = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'delivery',
            'description' => 'Delivery Fee',
            'amount' => 5.00,
            'category_id' => null,
        ]);
        $this->lumpTax($order, '0.90');

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $order), [
                'rate' => '0.06000',
                'component_ids' => [$component->id, $delivery->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('component_tax_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'type' => 'delivery',
            'normalized_description' => 'delivery fee',
            'is_taxable' => true,
        ]);

        $next = $this->order($user, $merchant, $batch, '10.00', '0.90', '15.90', 'NEXT-DELIVERY');
        $next->update(['delivery_fee' => 5]);
        $next->refresh();
        $this->item($next, $soap, 1, '10.00', 'Soap');

        app(OrderComponentGenerator::class)->generateForOrder($next);

        $this->assertNull(
            OrderComponent::query()->where('order_id', $next->id)->where('description', 'Sales Tax')->first(),
        );
        $this->assertSame(2, OrderComponent::query()->where('order_id', $next->id)->where('type', 'tax')->count());
        $this->assertSame(1, ComponentTaxRule::query()->count());
    }

    public function test_allocated_lump_tax_is_rehomed_onto_the_partials(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111');
        $order = $this->order($user, $merchant, $batch, '10.00', '0.60', '10.60');
        $order->update(['status' => 'reconciled']);
        $item = $this->item($order, $soap, 1, '10.00', 'Soap');
        $component = $this->productComponent($order, $item, null);
        $lump = $this->lumpTax($order, '0.60');
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -0.60,
        ]);
        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $lump->id,
            'allocated_amount' => 0.60,
            'allocation_type' => TransactionAllocation::TYPE_AUTOMATIC,
        ]);

        $this->actingAs($user)
            ->post(route('reconciliation.orders.tax-reconciliation', $order), [
                'rate' => '0.06000',
                'component_ids' => [$component->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('transaction_allocations', [
            'order_component_id' => $lump->id,
        ]);
        $this->assertDatabaseMissing('order_components', ['id' => $lump->id]);

        $partial = OrderComponent::query()->where('order_id', $order->id)->where('type', 'tax')->first();
        $this->assertNotNull($partial);
        $this->assertDatabaseHas('transaction_allocations', [
            'order_component_id' => $partial->id,
            'bank_transaction_id' => $transaction->id,
            'allocated_amount' => '0.60',
            'allocation_type' => TransactionAllocation::TYPE_AUTOMATIC,
        ]);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertSame(0.6, $order->fresh()->allocated_amount);
    }

    public function test_untaxed_order_learns_unknown_items_as_exempt(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $taxable = $this->product($user, $merchant, 'Soap', '111', true);

        $untaxed = $this->order($user, $merchant, $batch, '14.00', '0.00', '17.00', 'NO-TAX');
        $untaxed->update(['delivery_fee' => 3]);
        $untaxed->refresh();
        $this->item($untaxed, null, 1, '10.00', 'Milk');
        $this->item($untaxed, $taxable, 2, '4.00', 'Soap');

        $this->assertTrue(app(OrderComponentGenerator::class)->generateForOrder($untaxed));

        $milk = Product::query()->where('user_id', $user->id)->where('normalized_name', 'milk')->first();

        $this->assertNotNull($milk);
        $this->assertFalse($milk->is_taxable);
        $this->assertTrue($taxable->fresh()->is_taxable);
        $this->assertFalse((bool) OrderItem::query()->where('order_id', $untaxed->id)->where('description', 'Milk')->value('taxable'));
        $this->assertFalse((bool) OrderItem::query()->where('order_id', $untaxed->id)->where('description', 'Soap')->value('taxable'));
        $this->assertDatabaseHas('component_tax_rules', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'type' => 'delivery',
            'normalized_description' => 'delivery fee',
            'is_taxable' => false,
        ]);

        $alreadyBuilt = $this->order($user, $merchant, $batch, '6.00', '0.00', '6.00', 'NO-TAX-OLD');
        $cereal = $this->product($user, $merchant, 'Cereal', '333');
        $cerealItem = $this->item($alreadyBuilt, $cereal, 1, '6.00', 'Cereal');
        $this->productComponent($alreadyBuilt, $cerealItem, null);

        $this->assertFalse(app(OrderComponentGenerator::class)->generateForOrder($alreadyBuilt));
        $this->assertSame(2, app(OrderTaxReconciler::class)->learnExemptFromUntaxedOrders());
        $this->assertFalse($cereal->fresh()->is_taxable);
    }

    public function test_save_rejects_a_selection_that_does_not_match_the_tax(): void
    {
        [$user, $merchant, $batch] = $this->walmartContext();
        $soap = $this->product($user, $merchant, 'Soap', '111');
        $milk = $this->product($user, $merchant, 'Milk', '222');
        $order = $this->order($user, $merchant, $batch, '14.00', '0.60', '14.60');
        $soapItem = $this->item($order, $soap, 1, '10.00', 'Soap');
        $milkItem = $this->item($order, $milk, 2, '4.00', 'Milk');
        $soapComponent = $this->productComponent($order, $soapItem, null);
        $milkComponent = $this->productComponent($order, $milkItem, null);
        $lump = $this->lumpTax($order, '0.60');

        $this->actingAs($user)
            ->from(route('orders.detail', ['merchant' => 'walmart', 'order' => $order->id]))
            ->post(route('reconciliation.orders.tax-reconciliation', $order), [
                'rate' => '0.06000',
                'component_ids' => [$soapComponent->id, $milkComponent->id],
            ])
            ->assertRedirect(route('orders.detail', ['merchant' => 'walmart', 'order' => $order->id]))
            ->assertSessionHasErrors('component_ids');

        $this->assertNotNull(OrderComponent::query()->find($lump->id));
        $this->assertNull($soap->fresh()->is_taxable);
    }

    /**
     * @return array{0: User, 1: Merchant, 2: ImportBatch}
     */
    private function walmartContext(): array
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        return [$user, $merchant, $batch];
    }

    private function product(User $user, Merchant $merchant, string $name, string $sku, ?bool $taxable = null): Product
    {
        return Product::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'category_id' => null,
            'name' => $name,
            'normalized_name' => strtolower($name),
            'sku' => $sku,
            'is_taxable' => $taxable,
        ]);
    }

    private function order(
        User $user,
        Merchant $merchant,
        ImportBatch $batch,
        string $subtotal,
        string $tax,
        string $total,
        string $number = 'ORD-TAX',
    ): Order {
        return Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'order_number' => $number,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'delivery_fee' => 0,
            'tip' => 0,
            'discount' => 0,
            'total' => $total,
        ]);
    }

    private function item(Order $order, ?Product $product, int $line, string $price, string $description): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'line_number' => $line,
            'sku' => $product?->sku,
            'description' => $description,
            'normalized_description' => strtolower($description),
            'quantity' => 1,
            'unit_price' => $price,
            'extended_price' => $price,
            'taxable' => true,
        ]);
    }

    private function productComponent(Order $order, OrderItem $item, ?int $categoryId): OrderComponent
    {
        return OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => $item->description,
            'amount' => $item->extended_price,
            'category_id' => $categoryId,
            'category_confidence' => $categoryId !== null ? 100 : null,
        ]);
    }

    private function lumpTax(Order $order, string $amount): OrderComponent
    {
        return OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'tax',
            'description' => 'Sales Tax',
            'amount' => $amount,
            'category_id' => null,
            'category_confidence' => null,
            'metadata' => [],
        ]);
    }
}
