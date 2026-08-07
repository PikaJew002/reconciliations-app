<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Imports\Contracts\Importer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WalmartOrderImporter implements Importer
{
    public function import(ImportBatch $batch): int
    {
        $merchant = $this->resolveMerchant($batch);
        $created = 0;

        foreach ($this->orders($batch->storage_path) as $orderData) {
            $orderAttributes = $this->mapOrder($orderData);

            if ($orderAttributes === null) {
                continue;
            }

            $orderMetadata = $orderData;
            unset($orderMetadata['items']);

            $metadataPayments = $orderAttributes['metadata_payments'] ?? [];
            unset($orderAttributes['metadata_payments']);

            if ($metadataPayments !== []) {
                $orderMetadata['payments'] = $metadataPayments;
            }

            $order = Order::query()->firstOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'order_number' => $orderAttributes['order_number'],
                ],
                [
                    ...$orderAttributes,
                    'user_id' => $batch->user_id,
                    'import_batch_id' => $batch->id,
                    'status' => 'imported',
                    'metadata' => $orderMetadata,
                ],
            );

            if (! $order->wasRecentlyCreated) {
                continue;
            }

            foreach ($this->mapItems($orderData['items'] ?? []) as $lineNumber => $itemAttributes) {
                OrderItem::create([
                    ...$itemAttributes,
                    'order_id' => $order->id,
                    'line_number' => $lineNumber,
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
     * @return list<array<string, mixed>>
     */
    protected function orders(string $storagePath): array
    {
        $contents = Storage::disk('local')->get($storagePath);

        if ($contents === null) {
            throw new RuntimeException("Import file is not readable: {$storagePath}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Walmart order import file must contain a JSON array.');
        }

        if ($decoded !== [] && array_is_list($decoded) === false) {
            throw new RuntimeException('Walmart order import file must contain a JSON array of orders.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>|null
     */
    protected function mapOrder(array $order): ?array
    {
        $orderNumber = trim((string) ($order['orderNumber'] ?? ''));
        $subtotal = $this->parseMoney($order['orderSubtotal'] ?? null);
        $total = $this->parseMoney($order['orderTotal'] ?? null);

        if ($orderNumber === '' || $subtotal === null || $total === null) {
            return null;
        }

        $payments = $this->parsePaymentMethods($order);

        return [
            'order_number' => $orderNumber,
            'ordered_at' => $this->parseOrderDate($order['orderDate'] ?? null),
            'delivered_at' => $this->parseOrderDate($order['deliveredDate'] ?? null),
            'subtotal' => $subtotal,
            'tax' => $this->parseMoney($order['tax'] ?? null) ?? 0,
            'delivery_fee' => $this->parseMoney($order['deliveryCharges'] ?? null) ?? 0,
            'tip' => $this->parseMoney($order['tip'] ?? null) ?? 0,
            'discount' => $this->parseMoney($order['savings'] ?? null) ?? 0,
            'total' => $total,
            'currency' => 'USD',
            'payment_last_four' => $this->resolvePaymentLastFour($payments),
            'metadata_payments' => $payments,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array{ending: string|null, last_four: string|null, amount: float|null}>
     */
    protected function parsePaymentMethods(array $order): array
    {
        $details = $order['paymentMethodDetails'] ?? [];

        if (! is_array($details) || $details === []) {
            $fallback = trim((string) ($order['paymentMethods'] ?? ''));

            if ($fallback === '') {
                return [];
            }

            return [[
                'ending' => $fallback,
                'last_four' => $this->extractLastFour($fallback),
                'amount' => null,
            ]];
        }

        $payments = [];

        foreach ($details as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            $ending = trim((string) ($detail['ending'] ?? ''));

            if ($ending === '') {
                continue;
            }

            $amount = $this->parseMoney($detail['amount'] ?? null);

            $payments[] = [
                'ending' => $ending,
                'last_four' => $this->extractLastFour($ending),
                'amount' => $amount,
            ];
        }

        return $payments;
    }

    /**
     * @param  list<array{ending: string|null, last_four: string|null, amount: float|null}>  $payments
     */
    protected function resolvePaymentLastFour(array $payments): ?string
    {
        if ($payments === []) {
            return null;
        }

        if (count($payments) > 1) {
            return null;
        }

        return $payments[0]['last_four'];
    }

    protected function extractLastFour(string $value): ?string
    {
        if (preg_match('/(\d{4})\s*$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $mapped = [];
        $lineNumber = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $attributes = $this->mapItem($item);

            if ($attributes === null) {
                continue;
            }

            $mapped[$lineNumber] = $attributes;
            $lineNumber++;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function mapItem(array $item): ?array
    {
        $description = trim((string) ($item['productName'] ?? ''));
        $quantity = $this->parseQuantity($item['quantity'] ?? null);
        $extendedPrice = $this->parseMoney($item['price'] ?? null);

        if ($description === '' || $quantity === null || $quantity <= 0 || $extendedPrice === null) {
            return null;
        }

        $unitPrice = round($extendedPrice / $quantity, 2);
        $sku = trim((string) ($item['usItemId'] ?? ''));

        return [
            'sku' => $sku !== '' ? $sku : null,
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'extended_price' => $extendedPrice,
            'metadata' => $item,
        ];
    }

    protected function parseMoney(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace([',', '$'], '', $value);

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    protected function parseQuantity(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    protected function parseOrderDate(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\s+purchase$/i', '', $value) ?? $value;

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
