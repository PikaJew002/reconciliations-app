<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Imports\Concerns\ReadsCsv;
use App\Services\Imports\Contracts\Importer;
use RuntimeException;

class WalmartOrderImporter implements Importer
{
    use ReadsCsv;

    public function import(ImportBatch $batch): int
    {
        $merchant = $this->resolveMerchant($batch);
        $created = 0;

        foreach ($this->rows($batch->storage_path) as $row) {
            $orderAttributes = $this->mapOrderRow($row);

            if ($orderAttributes === null) {
                continue;
            }

            $order = Order::create([
                ...$orderAttributes,
                'user_id' => $batch->user_id,
                'import_batch_id' => $batch->id,
                'merchant_id' => $merchant->id,
                'status' => 'imported',
                'metadata' => $row,
            ]);

            $itemAttributes = $this->mapItemRow($row);

            if ($itemAttributes !== null) {
                OrderItem::create([
                    ...$itemAttributes,
                    'order_id' => $order->id,
                    'metadata' => $row,
                ]);
            }

            $created++;
        }

        return $created;
    }

    protected function resolveMerchant(ImportBatch $batch): Merchant
    {
        $merchantId = $batch->metadata['merchant_id'] ?? null;

        if ($merchantId) {
            $merchant = Merchant::query()
                ->where('user_id', $batch->user_id)
                ->whereKey($merchantId)
                ->first();

            if (! $merchant) {
                throw new RuntimeException('Merchant not found for this import batch.');
            }

            return $merchant;
        }

        return Merchant::query()->firstOrCreate(
            [
                'user_id' => $batch->user_id,
                'normalized_name' => 'walmart',
            ],
            [
                'name' => 'Walmart',
                'type' => Merchant::RETAILER,
                'supports_order_import' => true,
                'supports_api' => false,
                'website' => 'https://www.walmart.com',
                'metadata' => [],
            ],
        );
    }

    /**
     * Map a CSV row to Order attributes.
     *
     * Field matching is intentionally left empty until Walmart column
     * mapping is implemented.
     *
     * Expected keys when implemented: order_number, ordered_at, fulfilled_at,
     * delivered_at, subtotal, tax, delivery_fee, tip, discount, total, currency.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapOrderRow(array $row): ?array
    {
        // TODO: Map CSV columns to order fields.
        return null;
    }

    /**
     * Map a CSV row to OrderItem attributes.
     *
     * Field matching is intentionally left empty until Walmart line-item
     * column mapping is implemented.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapItemRow(array $row): ?array
    {
        // TODO: Map CSV columns to order item fields.
        return null;
    }
}
