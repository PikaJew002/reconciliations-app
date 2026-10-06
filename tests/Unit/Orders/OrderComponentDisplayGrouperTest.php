<?php

namespace Tests\Unit\Orders;

use App\Services\Orders\OrderComponentDisplayGrouper;
use PHPUnit\Framework\TestCase;

class OrderComponentDisplayGrouperTest extends TestCase
{
    public function test_same_product_lines_collapse_to_one_quantity(): void
    {
        $rows = (new OrderComponentDisplayGrouper)->rows(
            [
                $this->component(1, 'Seasoning', 0.77, 10, 1),
                $this->component(2, 'Seasoning', 0.77, 11, 1),
                $this->component(3, 'Milk', 4, 12, 2),
                $this->component(4, 'Sales Tax', 0.20, null, null, 'tax'),
            ],
            [
                $this->item(10, 'Seasoning', 'SKU-1', 1, 0.77),
                $this->item(11, 'Seasoning', null, 1, 0.77),
                $this->item(12, 'Milk', 'SKU-2', 2, 4),
            ],
        );

        $this->assertCount(3, $rows);
        $this->assertSame([1, 2], $rows[0]['component_ids']);
        $this->assertSame(2.0, $rows[0]['quantity']);
        $this->assertSame(1.54, $rows[0]['amount']);
        $this->assertSame('SKU-1', $rows[0]['sku']);
        $this->assertFalse($rows[0]['can_edit_quantity']);
        $this->assertNull($rows[0]['order_item_id']);
        $this->assertSame(2.0, $rows[1]['quantity']);
        $this->assertTrue($rows[1]['can_edit_quantity']);
        $this->assertSame('tax', $rows[2]['type']);
        $this->assertSame([4], $rows[2]['component_ids']);
    }

    public function test_different_skus_and_prices_stay_separate(): void
    {
        $rows = (new OrderComponentDisplayGrouper)->rows(
            [
                $this->component(1, 'Dress', 5.98, 1),
                $this->component(2, 'Dress', 5.98, 2),
                $this->component(3, 'Dress', 7.50, 3),
            ],
            [
                $this->item(1, 'Dress', 'AAA', 1, 5.98),
                $this->item(2, 'Dress', 'BBB', 1, 5.98),
                $this->item(3, 'Dress', 'AAA', 1, 7.50),
            ],
        );

        $this->assertCount(3, $rows);
        $this->assertSame([1], $rows[0]['component_ids']);
        $this->assertSame('AAA', $rows[0]['sku']);
        $this->assertSame([2], $rows[1]['component_ids']);
        $this->assertSame('BBB', $rows[1]['sku']);
        $this->assertSame([3], $rows[2]['component_ids']);
    }

    public function test_refunded_and_manual_lines_do_not_join_the_group(): void
    {
        $rows = (new OrderComponentDisplayGrouper)->rows(
            [
                $this->component(1, 'Seasoning', 0.77, 1),
                $this->component(2, 'Seasoning', 0.77, 2, 1, 'product', refund: 0.77),
                $this->component(3, 'Seasoning', 0.77, 3, 1, 'product', manual: true),
            ],
            [
                $this->item(1, 'Seasoning', 'SKU', 1, 0.77),
                $this->item(2, 'Seasoning', 'SKU', 1, 0.77),
                $this->item(3, 'Seasoning', 'SKU', 1, 0.77),
            ],
        );

        $this->assertCount(3, $rows);
        $this->assertSame(0.77, $rows[1]['refund_amount']);
        $this->assertTrue($rows[2]['is_user_modified']);
    }

    public function test_item_without_a_product_component_stays_visible(): void
    {
        $rows = (new OrderComponentDisplayGrouper)->rows(
            [
                $this->component(1, 'Sales Tax', 0.20, null, null, 'tax'),
            ],
            [
                $this->item(9, 'Bananas', '443909', 3, 0.2),
            ],
            allowOrphanQuantityEdits: true,
        );

        $this->assertCount(2, $rows);
        $this->assertSame('item-9', $rows[0]['key']);
        $this->assertSame([], $rows[0]['component_ids']);
        $this->assertSame(3.0, $rows[0]['quantity']);
        $this->assertSame(0.6, $rows[0]['amount']);
        $this->assertTrue($rows[0]['can_edit_quantity']);
        $this->assertFalse($rows[0]['can_delete']);
        $this->assertSame('tax', $rows[1]['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function component(
        int $id,
        string $description,
        float $amount,
        ?int $itemId,
        ?float $quantity = 1,
        string $type = 'product',
        ?float $refund = null,
        bool $manual = false,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'refund_amount' => $refund,
            'refund_kind' => $refund === null ? null : 'bank',
            'category' => null,
            'category_id' => null,
            'is_user_modified' => $manual,
            'allocated_amount' => 0,
            'remaining_amount' => $amount,
            'can_refund' => true,
            'can_delete' => true,
            'order_item_id' => $itemId,
            'quantity' => $type === 'product' ? $quantity : null,
            'unit_price' => $type === 'product' && $itemId !== null ? $amount / max($quantity ?? 1, 0.001) : null,
            'can_edit_quantity' => $type === 'product' && $itemId !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $id, string $description, ?string $sku, float $quantity, float $unitPrice): array
    {
        return [
            'id' => $id,
            'description' => $description,
            'sku' => $sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'extended_price' => round($quantity * $unitPrice, 2),
        ];
    }
}
