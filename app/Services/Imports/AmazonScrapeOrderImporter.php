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

class AmazonScrapeOrderImporter implements Importer
{
    public function import(ImportBatch $batch): int
    {
        $merchant = $this->resolveMerchant($batch);
        $created = 0;

        foreach ($this->details($batch->storage_path) as $detail) {
            $orderAttributes = $this->mapOrder($detail);

            if ($orderAttributes === null) {
                continue;
            }

            $metadataPayments = $orderAttributes['metadata_payments'] ?? [];
            unset($orderAttributes['metadata_payments']);

            $orderMetadata = is_array($detail['data'] ?? null) ? $detail['data'] : $detail;
            $orderMetadata['payments'] = $metadataPayments;

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

            foreach ($this->mapItems($this->itemRows($detail)) as $lineNumber => $itemAttributes) {
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
                'normalized_name' => 'amazon',
            ],
            [
                'name' => 'Amazon',
                'type' => Merchant::RETAILER,
                'supports_order_import' => true,
                'supports_api' => false,
                'website' => 'https://www.amazon.com',
                'metadata' => [],
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function details(string $storagePath): array
    {
        $contents = Storage::disk('local')->get($storagePath);

        if ($contents === null) {
            throw new RuntimeException("Import file is not readable: {$storagePath}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Amazon scrape import file must contain a JSON object.');
        }

        $details = $decoded['details'] ?? null;

        if (! is_array($details)) {
            throw new RuntimeException('Amazon scrape import file must contain a details array.');
        }

        return array_values(array_filter(
            $details,
            fn (mixed $detail): bool => is_array($detail),
        ));
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    protected function mapOrder(array $detail): ?array
    {
        if (array_key_exists('success', $detail) && $detail['success'] !== true) {
            return null;
        }

        $data = is_array($detail['data'] ?? null) ? $detail['data'] : [];
        $orderNumber = trim((string) ($data['orderNumber'] ?? $detail['orderNumber'] ?? ''));

        if ($orderNumber === '') {
            return null;
        }

        $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
        $mappedItems = $this->mapItems($this->itemRows($detail));
        $cardTotal = $this->parseMoney($summary['grand_total'] ?? null) ?? 0.0;
        $giftTotal = abs($this->parseMoney($summary['gift_card_amount'] ?? null) ?? 0.0);
        $tax = $this->parseMoney($summary['estimated_tax_to_be_collected'] ?? null) ?? 0.0;
        $paid = round($cardTotal + $giftTotal, 2);

        if ($mappedItems === [] && $paid < 0.01) {
            return null;
        }

        $itemSubtotal = round(array_sum(array_column($mappedItems, 'extended_price')), 2);
        $subtotal = $this->parseMoney($summary['items_subtotal'] ?? null) ?? $itemSubtotal;
        $residual = round($paid - $subtotal - $tax, 2);
        $payments = $this->buildPayments($data, $cardTotal, $giftTotal);

        return [
            'order_number' => $orderNumber,
            'ordered_at' => $this->parseOrderDate($data['orderDate'] ?? null),
            'delivered_at' => null,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'delivery_fee' => max(0.0, $residual),
            'tip' => 0,
            'discount' => abs(min(0.0, $residual)),
            'total' => $paid,
            'currency' => 'USD',
            'payment_last_four' => $this->resolvePaymentLastFour($payments),
            'metadata_payments' => $payments,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<array<string, mixed>>
     */
    protected function itemRows(array $detail): array
    {
        $data = is_array($detail['data'] ?? null) ? $detail['data'] : [];
        $shipments = $data['shipments'] ?? [];

        if (! is_array($shipments)) {
            return [];
        }

        $items = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                continue;
            }

            $shipmentItems = $shipment['items'] ?? [];

            if (! is_array($shipmentItems)) {
                continue;
            }

            foreach ($shipmentItems as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{ending: string, last_four: string|null, amount: float, kind: string}>
     */
    protected function buildPayments(array $data, float $cardTotal, float $giftTotal): array
    {
        $method = $this->normalizePaymentMethod((string) ($data['paymentMethod'] ?? ''));
        $lastFour = $this->extractLastFour($method);
        $kind = $this->classifyPaymentKind($method, $lastFour);
        $payments = [];

        if ($cardTotal >= 0.01) {
            $payments[] = [
                'ending' => $method !== '' ? $method : 'Card',
                'last_four' => $lastFour,
                'amount' => $cardTotal,
                'kind' => $kind === 'gift_card' ? 'card' : $kind,
            ];
        }

        if ($giftTotal >= 0.01) {
            $payments[] = [
                'ending' => 'Amazon gift card balance',
                'last_four' => null,
                'amount' => $giftTotal,
                'kind' => 'gift_card',
            ];
        }

        return $payments;
    }

    protected function normalizePaymentMethod(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/([A-Za-z])ending in/i', '$1 ending in', $value) ?? $value;
    }

    protected function classifyPaymentKind(string $ending, ?string $lastFour): string
    {
        $lower = Str::of($ending)->lower()->squish()->toString();

        if (str_contains($lower, 'gift') || str_contains($lower, 'amazon balance')) {
            return 'gift_card';
        }

        if (preg_match('/\b(mastercard|visa|amex|american express|discover)\b/', $lower) === 1) {
            return 'card';
        }

        if ($lastFour !== null) {
            return 'card';
        }

        return 'unknown';
    }

    /**
     * @param  list<array{ending: string, last_four: string|null, amount: float, kind: string}>  $payments
     */
    protected function resolvePaymentLastFour(array $payments): ?string
    {
        $cardPayments = array_values(array_filter(
            $payments,
            fn (array $payment): bool => $payment['kind'] === 'card',
        ));

        if (count($payments) !== 1 || count($cardPayments) !== 1) {
            return null;
        }

        return $cardPayments[0]['last_four'];
    }

    protected function extractLastFour(string $value): ?string
    {
        if (preg_match('/ending in\s+(\d{4})\b/i', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        $mapped = [];
        $lineNumber = 1;

        foreach ($items as $item) {
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
        $description = trim((string) ($item['title'] ?? ''));
        $quantity = $this->parseQuantity($item['quantity'] ?? null);
        $unitPrice = $this->parseMoney($item['unitPrice'] ?? null);

        if ($description === '' || $quantity === null || $quantity <= 0 || $unitPrice === null) {
            return null;
        }

        $asin = trim((string) ($item['asin'] ?? ''));

        return [
            'sku' => $asin !== '' ? $asin : null,
            'description' => $description,
            'normalized_description' => Str::of($description)->lower()->squish()->toString(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'extended_price' => round($unitPrice * $quantity, 2),
            'metadata' => $item,
        ];
    }

    protected function parseMoney(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

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
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

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

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
