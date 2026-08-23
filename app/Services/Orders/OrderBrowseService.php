<?php

namespace App\Services\Orders;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrderBrowseService
{
    /**
     * @var list<array{normalized_name: string, name: string}>
     */
    public const BROWSABLE_MERCHANTS = [
        ['normalized_name' => 'walmart', 'name' => 'Walmart'],
        ['normalized_name' => 'amazon', 'name' => 'Amazon'],
    ];

    public function __construct(
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     retailers: list<array<string, mixed>>,
     *     bankCoverage: array{min: ?string, max: ?string}|null
     * }
     */
    public function index(int $userId): array
    {
        $coverageByMerchant = Order::query()
            ->where('user_id', $userId)
            ->whereNotNull('merchant_id')
            ->selectRaw('merchant_id, MIN(ordered_at) as min_ordered_at, MAX(ordered_at) as max_ordered_at, COUNT(*) as order_count')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $merchantIds = $coverageByMerchant->keys()->all();

        $merchantsByNormalized = $merchantIds === []
            ? collect()
            : Merchant::query()
                ->whereIn('id', $merchantIds)
                ->get()
                ->keyBy('normalized_name');

        $retailers = collect(self::BROWSABLE_MERCHANTS)
            ->map(function (array $vendor) use ($merchantsByNormalized, $coverageByMerchant): array {
                $merchant = $merchantsByNormalized->get($vendor['normalized_name']);
                $coverage = $merchant ? $coverageByMerchant->get($merchant->id) : null;

                $min = $coverage?->min_ordered_at
                    ? Carbon::parse($coverage->min_ordered_at)->toDateString()
                    : null;
                $max = $coverage?->max_ordered_at
                    ? Carbon::parse($coverage->max_ordered_at)->toDateString()
                    : null;

                return [
                    'name' => $vendor['name'],
                    'normalized_name' => $vendor['normalized_name'],
                    'type' => Merchant::RETAILER,
                    'order_count' => (int) ($coverage?->order_count ?? 0),
                    'min_ordered_at' => $min,
                    'max_ordered_at' => $max,
                    'coverage_span_days' => $this->spanDays($min, $max),
                ];
            })
            ->values()
            ->all();

        return [
            'retailers' => $retailers,
            'bankCoverage' => $this->bankCoverage($userId),
        ];
    }

    /**
     * @return array{
     *     merchant: array{name: string, normalized_name: string},
     *     orders: list<array<string, mixed>>,
     *     ordersTruncated: bool,
     *     orderCoverage: array{min: ?string, max: ?string}|null,
     *     bankCoverage: array{min: ?string, max: ?string}|null,
     *     nearImportEdge: bool,
     *     filters: array{q: string, merchant: string}
     * }
     */
    public function show(int $userId, string $merchantNormalized, ?string $query = null): array
    {
        $vendor = $this->resolveBrowsableMerchant($merchantNormalized);

        $query = trim((string) $query);
        $merchantNormalized = $vendor['normalized_name'];

        $bankCoverage = $this->bankCoverage($userId);
        $ordersQuery = $this->baseOrdersQuery($userId, $merchantNormalized);

        if ($query !== '') {
            $ordersQuery->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('order_number', 'like', "%{$query}%")
                    ->orWhere('total', 'like', "%{$query}%");
            });
        }

        $orderCoverage = $this->orderCoverage(clone $ordersQuery);
        $nearImportEdge = $this->coverageNearImportEdge($orderCoverage, $bankCoverage);

        $totalMatching = (clone $ordersQuery)->count();

        $orders = $ordersQuery
            ->with('merchant:id,name,normalized_name')
            ->withSum('components', 'amount')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(function (Order $order) use ($bankCoverage): array {
                $orderDate = $this->orderDate($order);
                $balance = $this->componentBalance(
                    (float) $order->total,
                    (float) ($order->components_sum_amount ?? 0),
                );

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'ordered_at' => optional($order->ordered_at)?->toDateString(),
                    'delivered_at' => optional($order->delivered_at)?->toDateString(),
                    'total' => (float) $order->total,
                    'status' => $order->status,
                    'payment_last_four' => $order->payment_last_four,
                    'merchant' => $order->merchant?->only(['id', 'name', 'normalized_name']),
                    'near_import_edge' => $this->orderNearImportEdge($orderDate, $bankCoverage),
                    ...$balance,
                ];
            })
            ->values()
            ->all();

        return [
            'merchant' => $vendor,
            'orders' => $orders,
            'ordersTruncated' => $totalMatching > $this->listLimit,
            'orderCoverage' => $orderCoverage,
            'bankCoverage' => $bankCoverage,
            'nearImportEdge' => $nearImportEdge,
            'filters' => [
                'q' => $query,
                'merchant' => $merchantNormalized,
            ],
        ];
    }

    /**
     * @return array{
     *     merchant: array{normalized_name: string, name: string},
     *     order: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     components: list<array<string, mixed>>,
     *     can_delete: bool,
     *     has_allocations: bool
     * }
     */
    public function detail(int $userId, string $merchantNormalized, int $orderId): array
    {
        $vendor = $this->resolveBrowsableMerchant($merchantNormalized);
        $order = $this->findOwned($userId, $vendor['normalized_name'], $orderId);

        $order->load([
            'items' => fn ($query) => $query->orderBy('line_number')->orderBy('id'),
            'components' => fn ($query) => $query
                ->orderBy('id')
                ->with('category:id,name')
                ->withSum('allocations', 'allocated_amount'),
        ]);

        $components = $order->components
            ->map(function (OrderComponent $component): array {
                $allocated = round((float) ($component->allocations_sum_allocated_amount ?? 0), 2);
                $amount = (float) $component->amount;

                return [
                    'id' => $component->id,
                    'type' => $component->type,
                    'description' => $component->description,
                    'amount' => $amount,
                    'category' => $component->category?->only(['id', 'name']),
                    'allocated_amount' => $allocated,
                    'remaining_amount' => round($amount - $allocated, 2),
                ];
            })
            ->values()
            ->all();

        $hasAllocations = collect($components)->contains(
            fn (array $component): bool => abs($component['allocated_amount']) >= 0.01,
        );

        $balance = $this->componentBalance(
            (float) $order->total,
            (float) $order->components->sum('amount'),
        );

        return [
            'merchant' => $vendor,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'ordered_at' => optional($order->ordered_at)?->toDateString(),
                'delivered_at' => optional($order->delivered_at)?->toDateString(),
                'status' => $order->status,
                'payment_last_four' => $order->payment_last_four,
                'payments' => $this->mapPayments($order),
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax,
                'delivery_fee' => (float) $order->delivery_fee,
                'tip' => (float) $order->tip,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
                ...$balance,
            ],
            'items' => $order->items
                ->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'sku' => $item->sku,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'extended_price' => (float) $item->extended_price,
                ])
                ->values()
                ->all(),
            'components' => $components,
            'can_delete' => true,
            'has_allocations' => $hasAllocations,
        ];
    }

    public function findOwned(int $userId, string $merchantNormalized, int $orderId): Order
    {
        $vendor = $this->resolveBrowsableMerchant($merchantNormalized);

        $order = $this->baseOrdersQuery($userId, $vendor['normalized_name'])
            ->whereKey($orderId)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return $order;
    }

    /**
     * @return array{component_sum: float, gap: float, components_balanced: bool}
     */
    protected function componentBalance(float $total, float $componentSum): array
    {
        $componentSum = round($componentSum, 2);
        $total = round($total, 2);
        $gap = round($total - $componentSum, 2);

        return [
            'component_sum' => $componentSum,
            'gap' => $gap,
            'components_balanced' => abs($gap) < 0.01,
        ];
    }

    /**
     * @return list<array{ending: ?string, last_four: ?string, amount: ?float, kind: ?string}>
     */
    protected function mapPayments(Order $order): array
    {
        $payments = $order->metadata['payments'] ?? [];

        if (! is_array($payments)) {
            return [];
        }

        return collect($payments)
            ->filter(fn (mixed $payment): bool => is_array($payment))
            ->map(fn (array $payment): array => [
                'ending' => isset($payment['ending']) ? (string) $payment['ending'] : null,
                'last_four' => array_key_exists('last_four', $payment) && $payment['last_four'] !== null
                    ? (string) $payment['last_four']
                    : null,
                'amount' => isset($payment['amount']) ? (float) $payment['amount'] : null,
                'kind' => isset($payment['kind']) ? (string) $payment['kind'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{normalized_name: string, name: string}
     */
    protected function resolveBrowsableMerchant(string $merchantNormalized): array
    {
        $merchantNormalized = strtolower(trim($merchantNormalized));

        foreach (self::BROWSABLE_MERCHANTS as $vendor) {
            if ($vendor['normalized_name'] === $merchantNormalized) {
                return $vendor;
            }
        }

        throw new NotFoundHttpException;
    }

    protected function baseOrdersQuery(int $userId, string $merchantNormalized): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereHas('merchant', function (Builder $builder) use ($merchantNormalized): void {
                $builder->where('normalized_name', $merchantNormalized);
            });
    }

    /**
     * @return array{min: ?string, max: ?string}|null
     */
    protected function orderCoverage(Builder $ordersQuery): ?array
    {
        $row = (clone $ordersQuery)
            ->whereNotNull('ordered_at')
            ->selectRaw('MIN(ordered_at) as min_ordered_at, MAX(ordered_at) as max_ordered_at')
            ->first();

        if ($row?->min_ordered_at === null || $row?->max_ordered_at === null) {
            return null;
        }

        return [
            'min' => Carbon::parse($row->min_ordered_at)->toDateString(),
            'max' => Carbon::parse($row->max_ordered_at)->toDateString(),
        ];
    }

    /**
     * @return array{min: ?string, max: ?string}|null
     */
    protected function bankCoverage(int $userId): ?array
    {
        $min = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('posted_at')
            ->min('posted_at');

        $max = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('posted_at')
            ->max('posted_at');

        if ($min === null || $max === null) {
            return null;
        }

        return [
            'min' => Carbon::parse($min)->toDateString(),
            'max' => Carbon::parse($max)->toDateString(),
        ];
    }

    /**
     * @param  array{min: ?string, max: ?string}|null  $orderCoverage
     * @param  array{min: ?string, max: ?string}|null  $bankCoverage
     */
    protected function coverageNearImportEdge(?array $orderCoverage, ?array $bankCoverage): bool
    {
        if ($orderCoverage === null || $bankCoverage === null) {
            return false;
        }

        return $this->dateNearBankEdge(Carbon::parse($orderCoverage['min'])->startOfDay(), $bankCoverage)
            || $this->dateNearBankEdge(Carbon::parse($orderCoverage['max'])->startOfDay(), $bankCoverage);
    }

    /**
     * @param  array{min: ?string, max: ?string}|null  $bankCoverage
     */
    protected function orderNearImportEdge(?Carbon $orderDate, ?array $bankCoverage): bool
    {
        if ($orderDate === null || $bankCoverage === null) {
            return false;
        }

        return $this->dateNearBankEdge($orderDate->copy()->startOfDay(), $bankCoverage);
    }

    /**
     * @param  array{min: string, max: string}  $bankCoverage
     */
    protected function dateNearBankEdge(Carbon $orderDate, array $bankCoverage): bool
    {
        $min = Carbon::parse($bankCoverage['min'])->startOfDay();
        $max = Carbon::parse($bankCoverage['max'])->startOfDay();

        return $orderDate->lt($min) || $orderDate->gt($max);
    }

    protected function orderDate(Order $order): ?Carbon
    {
        $date = $order->ordered_at ?? $order->delivered_at;

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    protected function spanDays(?string $min, ?string $max): ?int
    {
        if ($min === null || $max === null) {
            return null;
        }

        return (int) abs(Carbon::parse($min)->startOfDay()->diffInDays(Carbon::parse($max)->startOfDay(), false));
    }
}
