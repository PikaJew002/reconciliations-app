<?php

namespace App\Services\Orders;

use App\Models\BankTransaction;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OrderBrowseService
{
    public function __construct(
        protected int $importEdgeWindowDays = 3,
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     orders: list<array<string, mixed>>,
     *     ordersTruncated: bool,
     *     orderCoverage: array{min: ?string, max: ?string}|null,
     *     bankCoverage: array{min: ?string, max: ?string}|null,
     *     nearImportEdge: bool,
     *     filters: array{q: string, merchant: string}
     * }
     */
    public function index(int $userId, ?string $query = null, string $merchantNormalized = 'walmart'): array
    {
        $query = trim((string) $query);
        $merchantNormalized = trim($merchantNormalized) !== ''
            ? trim($merchantNormalized)
            : 'walmart';

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
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(function (Order $order) use ($bankCoverage): array {
                $orderDate = $this->orderDate($order);

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
                ];
            })
            ->values()
            ->all();

        return [
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

        if ($orderDate->lt($min) || $orderDate->gt($max)) {
            return true;
        }

        if (abs($orderDate->diffInDays($min, false)) <= $this->importEdgeWindowDays) {
            return true;
        }

        if (abs($orderDate->diffInDays($max, false)) <= $this->importEdgeWindowDays) {
            return true;
        }

        return false;
    }

    protected function orderDate(Order $order): ?Carbon
    {
        $date = $order->ordered_at ?? $order->delivered_at;

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }
}
