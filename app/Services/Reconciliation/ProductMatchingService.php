<?php

namespace App\Services\Reconciliation;

use App\Models\OrderItem;
use App\Models\Product;

class ProductMatchingService
{
    /**
     * Merchants whose order lines should resolve to merchant-scoped products.
     *
     * @var list<string>
     */
    public const ELIGIBLE_MERCHANT_NORMALIZED_NAMES = [
        'walmart',
        'sams_club',
    ];

    /**
     * @return array{created: int, linked: int, skipped: int}
     */
    public function matchForUser(int $userId): array
    {
        $created = 0;
        $linked = 0;
        $skipped = 0;

        OrderItem::query()
            ->whereNull('product_id')
            ->whereHas('order', function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->whereHas('merchant', function ($merchantQuery): void {
                        $merchantQuery->whereIn(
                            'normalized_name',
                            self::ELIGIBLE_MERCHANT_NORMALIZED_NAMES,
                        );
                    });
            })
            ->with(['order.merchant'])
            ->orderBy('id')
            ->each(function (OrderItem $item) use (&$created, &$linked, &$skipped): void {
                $merchant = $item->order?->merchant;

                if ($merchant === null) {
                    $skipped++;

                    return;
                }

                $result = $this->linkOrCreateForItem($item);

                if ($result === null) {
                    $skipped++;

                    return;
                }

                if ($result['created']) {
                    $created++;
                } else {
                    $linked++;
                }
            });

        return [
            'created' => $created,
            'linked' => $linked,
            'skipped' => $skipped,
        ];
    }

    /**
     * Link an order item to an existing or newly created product.
     *
     * @return array{product: Product, created: bool}|null
     */
    public function linkOrCreateForItem(OrderItem $item): ?array
    {
        $item->loadMissing('order.merchant');

        $order = $item->order;
        $merchant = $order?->merchant;

        if ($order === null || $merchant === null) {
            return null;
        }

        if (! in_array($merchant->normalized_name, self::ELIGIBLE_MERCHANT_NORMALIZED_NAMES, true)) {
            return null;
        }

        $result = $this->resolveProduct(
            userId: (int) $order->user_id,
            merchantId: (int) $merchant->id,
            item: $item,
        );

        $item->update([
            'product_id' => $result['product']->id,
            'match_confidence' => 100,
        ]);

        return $result;
    }

    /**
     * @return array{product: Product, created: bool}
     */
    protected function resolveProduct(int $userId, int $merchantId, OrderItem $item): array
    {
        $sku = $this->normalizedSku($item->sku);

        if ($sku !== null) {
            $bySku = Product::query()
                ->where('user_id', $userId)
                ->where('merchant_id', $merchantId)
                ->where('sku', $sku)
                ->first();

            if ($bySku) {
                return ['product' => $bySku, 'created' => false];
            }
        }

        $normalizedName = $item->normalized_description
            ?: mb_strtolower(trim((string) $item->description));

        $byName = Product::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $merchantId)
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($byName) {
            if ($sku !== null && $byName->sku === null) {
                $byName->update(['sku' => $sku]);
            }

            return ['product' => $byName, 'created' => false];
        }

        $product = Product::query()->create([
            'user_id' => $userId,
            'merchant_id' => $merchantId,
            'category_id' => null,
            'name' => $item->description,
            'normalized_name' => $normalizedName,
            'sku' => $sku,
            'is_taxable' => (bool) $item->taxable,
            'category_confidence' => null,
            'is_user_modified' => false,
            'metadata' => [],
        ]);

        return ['product' => $product, 'created' => true];
    }

    protected function normalizedSku(mixed $sku): ?string
    {
        if ($sku === null) {
            return null;
        }

        $trimmed = trim((string) $sku);

        return $trimmed === '' ? null : $trimmed;
    }
}
