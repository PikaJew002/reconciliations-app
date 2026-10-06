<?php

namespace App\Services\Orders;

class OrderComponentDisplayGrouper
{
    /**
     * Collapse repeated product lines into one display row with a summed quantity.
     * Other component types stay one row each so fees, tax, and refunds keep their own actions.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function rows(array $components, array $items, bool $allowOrphanQuantityEdits = false): array
    {
        $itemsById = [];

        foreach ($items as $item) {
            $itemsById[$item['id']] = $item;
        }

        $groups = [];
        $indexByKey = [];

        foreach ($components as $component) {
            $key = $this->bucketKey($component);

            if (! isset($indexByKey[$key])) {
                $indexByKey[$key] = count($groups);
                $groups[] = [];
            }

            $groups[$indexByKey[$key]][] = $component;
        }

        $rows = [];
        $coveredItemIds = [];

        foreach ($groups as $members) {
            foreach ($this->splitBySku($members, $itemsById) as $splitMembers) {
                foreach ($splitMembers as $member) {
                    if ($member['type'] === 'product' && $member['order_item_id'] !== null) {
                        $coveredItemIds[$member['order_item_id']] = true;
                    }
                }

                $rows[] = $this->present($splitMembers, $itemsById);
            }
        }

        $orphans = [];

        foreach ($items as $item) {
            if (isset($coveredItemIds[$item['id']])) {
                continue;
            }

            $orphans[] = $this->orphan($item, $allowOrphanQuantityEdits);
        }

        if ($orphans === []) {
            return $rows;
        }

        $insertAt = count($rows);

        foreach ($rows as $index => $row) {
            if ($row['type'] !== 'product') {
                $insertAt = $index;
                break;
            }
        }

        array_splice($rows, $insertAt, 0, $orphans);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    protected function bucketKey(array $component): string
    {
        if ($component['type'] !== 'product') {
            return 'single:'.$component['id'];
        }

        return implode('|', [
            'product',
            mb_strtolower(trim((string) $component['description'])),
            $this->money($component['unit_price'] ?? null),
            (string) ($component['category_id'] ?? ''),
            (string) ($component['refund_kind'] ?? ''),
            array_key_exists('refund_amount', $component) && $component['refund_amount'] !== null
                ? $this->money($component['refund_amount'])
                : '',
            ! empty($component['is_user_modified']) ? '1' : '0',
        ]);
    }

    /**
     * Same description with two different SKUs stays as separate products.
     * A missing SKU joins the single known SKU for that description.
     *
     * @param  list<array<string, mixed>>  $members
     * @param  array<int, array<string, mixed>>  $itemsById
     * @return list<list<array<string, mixed>>>
     */
    protected function splitBySku(array $members, array $itemsById): array
    {
        if ($members === [] || $members[0]['type'] !== 'product' || count($members) < 2) {
            return [$members];
        }

        $skus = [];

        foreach ($members as $member) {
            $sku = $this->itemSku($member, $itemsById);

            if ($sku !== null) {
                $skus[$sku] = true;
            }
        }

        if (count($skus) <= 1) {
            return [$members];
        }

        $buckets = [];

        foreach ($members as $member) {
            $sku = $this->itemSku($member, $itemsById) ?? '';
            $buckets[$sku][] = $member;
        }

        return array_values($buckets);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  array<int, array<string, mixed>>  $itemsById
     * @return array<string, mixed>
     */
    protected function present(array $members, array $itemsById): array
    {
        $first = $members[0];
        $componentIds = [];
        $seenItemIds = [];
        $quantity = null;
        $amount = 0.0;
        $allocated = 0.0;
        $remaining = 0.0;
        $refund = null;
        $sku = null;
        $canDelete = true;
        $canRefund = true;

        foreach ($members as $member) {
            $componentIds[] = $member['id'];
            $amount = round($amount + (float) $member['amount'], 2);
            $allocated = round($allocated + (float) $member['allocated_amount'], 2);
            $remaining = round($remaining + (float) $member['remaining_amount'], 2);
            $canDelete = $canDelete && (bool) $member['can_delete'];
            $canRefund = $canRefund && (bool) $member['can_refund'];

            if ($member['refund_amount'] !== null) {
                $refund = round(($refund ?? 0) + (float) $member['refund_amount'], 2);
            }

            $itemId = $member['order_item_id'];

            if ($member['quantity'] !== null && ($itemId === null || ! isset($seenItemIds[$itemId]))) {
                if ($itemId !== null) {
                    $seenItemIds[$itemId] = true;
                }

                $quantity = round(($quantity ?? 0) + (float) $member['quantity'], 3);
            }

            if ($sku === null && $first['type'] === 'product') {
                $sku = $this->itemSku($member, $itemsById);
            }
        }

        $single = count($members) === 1;

        return [
            'key' => implode('-', $componentIds),
            'component_ids' => $componentIds,
            'order_item_id' => $single ? $first['order_item_id'] : null,
            'type' => $first['type'],
            'description' => $first['description'],
            'sku' => $sku,
            'quantity' => $quantity,
            'unit_price' => $first['unit_price'],
            'amount' => $amount,
            'refund_amount' => $refund,
            'refund_kind' => $first['refund_kind'],
            'category' => $first['category'],
            'category_id' => $first['category_id'],
            'is_user_modified' => (bool) $first['is_user_modified'],
            'allocated_amount' => $allocated,
            'remaining_amount' => $remaining,
            'can_refund' => $canRefund,
            'can_delete' => $canDelete,
            'can_edit_quantity' => $single
                && (bool) $first['can_edit_quantity']
                && $first['order_item_id'] !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function orphan(array $item, bool $allowQuantityEdits): array
    {
        $sku = $item['sku'] ?? null;

        return [
            'key' => 'item-'.$item['id'],
            'component_ids' => [],
            'order_item_id' => $item['id'],
            'type' => 'product',
            'description' => $item['description'],
            'sku' => $sku !== null && $sku !== '' ? (string) $sku : null,
            'quantity' => (float) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'amount' => (float) $item['extended_price'],
            'refund_amount' => null,
            'refund_kind' => null,
            'category' => null,
            'category_id' => null,
            'is_user_modified' => false,
            'allocated_amount' => 0.0,
            'remaining_amount' => (float) $item['extended_price'],
            'can_refund' => false,
            'can_delete' => false,
            'can_edit_quantity' => $allowQuantityEdits,
        ];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<int, array<string, mixed>>  $itemsById
     */
    protected function itemSku(array $component, array $itemsById): ?string
    {
        $itemId = $component['order_item_id'] ?? null;

        if ($itemId === null || ! isset($itemsById[$itemId])) {
            return null;
        }

        $sku = $itemsById[$itemId]['sku'] ?? null;

        if ($sku === null || $sku === '') {
            return null;
        }

        return (string) $sku;
    }

    protected function money(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format(round((float) $amount, 2), 2, '.', '');
    }
}
