<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Imports\Concerns\ReadsCsv;
use App\Services\Imports\Contracts\Importer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AmazonOrderImporter implements Importer
{
    use ReadsCsv;

    /**
     * @var list<string>
     */
    protected array $requiredSummaryHeaders = [
        'order id',
        'date',
        'total',
        'gift',
        'tax',
        'payments',
    ];

    /**
     * @var list<string>
     */
    protected array $requiredItemHeaders = [
        'order id',
        'quantity',
        'description',
        'price',
    ];

    public function import(ImportBatch $batch): int
    {
        $merchant = $this->resolveMerchant($batch);
        $itemsPath = $batch->metadata['items_path'] ?? null;

        if (! is_string($itemsPath) || $itemsPath === '') {
            throw new RuntimeException('Amazon order import requires metadata.items_path.');
        }

        if (! Storage::disk('local')->exists($batch->storage_path)) {
            throw new RuntimeException("Summary import file is not readable: {$batch->storage_path}");
        }

        if (! Storage::disk('local')->exists($itemsPath)) {
            throw new RuntimeException("Items import file is not readable: {$itemsPath}");
        }

        $itemsByOrder = $this->loadItemsByOrder($itemsPath);
        $created = 0;

        foreach ($this->summaryRows($batch->storage_path) as $summary) {
            $orderNumber = $this->orderId($summary);

            if ($orderNumber === null) {
                continue;
            }

            $itemRows = $itemsByOrder[$orderNumber] ?? [];
            $orderAttributes = $this->mapOrder($summary, $itemRows);

            if ($orderAttributes === null) {
                continue;
            }

            $metadataPayments = $orderAttributes['metadata_payments'] ?? [];
            unset($orderAttributes['metadata_payments']);

            $orderMetadata = $summary;
            $orderMetadata['payments'] = $metadataPayments;
            $orderMetadata['shipping_column'] = $summary['shipping'] ?? null;

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

            foreach ($this->mapItems($itemRows) as $lineNumber => $itemAttributes) {
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
     * @return list<array<string, string|null>>
     */
    protected function summaryRows(string $storagePath): array
    {
        $rows = [];
        $validatedHeaders = false;

        foreach ($this->rows($storagePath) as $row) {
            if (! $validatedHeaders) {
                $this->assertHeaders($row, $this->requiredSummaryHeaders, 'summary');
                $validatedHeaders = true;
            }

            if ($this->orderId($row) === null) {
                continue;
            }

            $rows[] = $row;
        }

        if (! $validatedHeaders) {
            throw new RuntimeException('Amazon summary CSV is empty or missing headers.');
        }

        return $rows;
    }

    /**
     * @return array<string, list<array<string, string|null>>>
     */
    protected function loadItemsByOrder(string $storagePath): array
    {
        $itemsByOrder = [];
        $validatedHeaders = false;

        foreach ($this->rows($storagePath) as $row) {
            if (! $validatedHeaders) {
                $this->assertHeaders($row, $this->requiredItemHeaders, 'items');
                $validatedHeaders = true;
            }

            $orderNumber = $this->orderId($row);

            if ($orderNumber === null) {
                continue;
            }

            $itemsByOrder[$orderNumber][] = $row;
        }

        if (! $validatedHeaders) {
            throw new RuntimeException('Amazon items CSV is empty or missing headers.');
        }

        return $itemsByOrder;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  list<string>  $required
     */
    protected function assertHeaders(array $row, array $required, string $label): void
    {
        $headers = array_keys($row);

        foreach ($required as $header) {
            if (! in_array($header, $headers, true)) {
                throw new RuntimeException(
                    "Amazon {$label} CSV is missing required header [{$header}].",
                );
            }
        }
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function orderId(array $row): ?string
    {
        $orderId = trim((string) ($row['order id'] ?? ''));

        if ($orderId === '' || strtolower($orderId) === 'order id') {
            return null;
        }

        return $orderId;
    }

    /**
     * @param  array<string, string|null>  $summary
     * @param  list<array<string, string|null>>  $itemRows
     * @return array<string, mixed>|null
     */
    protected function mapOrder(array $summary, array $itemRows): ?array
    {
        $orderNumber = $this->orderId($summary);

        if ($orderNumber === null) {
            return null;
        }

        $mappedItems = $this->mapItems($itemRows);
        $cardTotal = $this->parseMoney($summary['total'] ?? null) ?? 0.0;
        $giftTotal = $this->parseMoney($summary['gift'] ?? null) ?? 0.0;
        $tax = $this->parseMoney($summary['tax'] ?? null) ?? 0.0;
        $paid = round($cardTotal + $giftTotal, 2);

        if ($mappedItems === [] && $paid < 0.01) {
            return null;
        }

        $subtotal = round(array_sum(array_column($mappedItems, 'extended_price')), 2);
        $residual = round($paid - $subtotal - $tax, 2);
        $deliveryFee = max(0.0, $residual);
        $discount = abs(min(0.0, $residual));
        $payments = $this->buildPayments($summary, $cardTotal, $giftTotal);

        return [
            'order_number' => $orderNumber,
            'ordered_at' => $this->parseOrderDate($summary['date'] ?? null),
            'delivered_at' => null,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'delivery_fee' => $deliveryFee,
            'tip' => 0,
            'discount' => $discount,
            'total' => $paid,
            'currency' => 'USD',
            'payment_last_four' => $this->resolvePaymentLastFour($payments),
            'metadata_payments' => $payments,
        ];
    }

    /**
     * @param  array<string, string|null>  $summary
     * @return list<array{ending: string, last_four: string|null, amount: float, kind: string}>
     */
    protected function buildPayments(array $summary, float $cardTotal, float $giftTotal): array
    {
        $parsed = $this->parsePaymentMethods((string) ($summary['payments'] ?? ''));
        $payments = [];

        if ($cardTotal >= 0.01) {
            $card = $this->firstPaymentOfKinds($parsed, ['card', 'unknown']);

            $payments[] = [
                'ending' => $card['ending'] ?? 'Card',
                'last_four' => $card['last_four'] ?? null,
                'amount' => $cardTotal,
                'kind' => 'card',
            ];
        }

        if ($giftTotal >= 0.01) {
            $gift = $this->firstPaymentOfKinds($parsed, ['gift_card']);

            $payments[] = [
                'ending' => $gift['ending'] ?? 'Amazon gift card balance',
                'last_four' => $gift['last_four'] ?? null,
                'amount' => $giftTotal,
                'kind' => 'gift_card',
            ];
        }

        return $payments;
    }

    /**
     * @param  list<array{ending: string, last_four: string|null, amount: float|null, kind: string}>  $payments
     * @param  list<string>  $kinds
     * @return array{ending: string, last_four: string|null, amount: float|null, kind: string}|null
     */
    protected function firstPaymentOfKinds(array $payments, array $kinds): ?array
    {
        foreach ($payments as $payment) {
            if (in_array($payment['kind'], $kinds, true)) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @return list<array{ending: string, last_four: string|null, amount: float|null, kind: string}>
     */
    protected function parsePaymentMethods(string $payments): array
    {
        $payments = trim($payments);

        if ($payments === '') {
            return [];
        }

        $parts = preg_split('/\s*;\s*/', $payments) ?: [];
        $parsed = [];

        foreach ($parts as $part) {
            $ending = trim((string) $part);

            if ($ending === '') {
                continue;
            }

            // Drop trailing "YYYY-MM-DD: $amount" details from Amazon exports.
            $label = preg_replace('/:\s*\d{4}-\d{2}-\d{2}:\s*\$?[\d,]+\.?\d*\s*$/', '', $ending) ?? $ending;
            $label = trim($label);

            if ($label === '') {
                continue;
            }

            $lastFour = $this->extractLastFour($label);

            $parsed[] = [
                'ending' => $label,
                'last_four' => $lastFour,
                'amount' => null,
                'kind' => $this->classifyPaymentKind($label, $lastFour),
            ];
        }

        return $parsed;
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
     * @param  list<array{ending: string, last_four: string|null, amount: float|null, kind: string}>  $payments
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
     * @param  list<array<string, string|null>>  $items
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
     * @param  array<string, string|null>  $item
     * @return array<string, mixed>|null
     */
    protected function mapItem(array $item): ?array
    {
        $description = trim((string) ($item['description'] ?? ''));
        $quantity = $this->parseQuantity($item['quantity'] ?? null);
        $unitPrice = $this->parseMoney($item['price'] ?? null);

        if ($description === '' || $quantity === null || $quantity <= 0 || $unitPrice === null) {
            return null;
        }

        $asin = trim((string) ($item['ASIN'] ?? $item['asin'] ?? ''));

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

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
