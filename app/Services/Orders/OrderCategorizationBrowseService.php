<?php

namespace App\Services\Orders;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

class OrderCategorizationBrowseService
{
    public const MODE_ITEMS = 'items';

    public const MODE_COMPONENTS = 'components';

    public const STATUS_NEEDS_PRODUCT = 'needs_product';

    public const STATUS_NEEDS_CATEGORY = 'needs_category';

    /**
     * Merchants shown on the order categorization queue.
     *
     * @var list<string>
     */
    public const QUEUE_MERCHANT_NORMALIZED_NAMES = [
        'walmart',
        'amazon',
    ];

    public function __construct(
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     orders: list<array<string, mixed>>,
     *     ordersTruncated: bool,
     *     categories: list<array{id: int, name: string, kind: string}>,
     *     filters: array{q: string}
     * }
     */
    public function index(int $userId, ?string $query = null): array
    {
        $query = trim((string) $query);

        $ordersQuery = $this->baseDirtyOrdersQuery($userId);

        if ($query !== '') {
            $ordersQuery->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('order_number', 'like', "%{$query}%")
                    ->orWhere('total', 'like', "%{$query}%");
            });
        }

        $totalMatching = (clone $ordersQuery)->count();

        $orders = $ordersQuery
            ->with([
                'merchant:id,name,normalized_name',
                'items.product:id,name,sku,category_id',
                'components' => fn ($builder) => $builder
                    ->where('type', 'product')
                    ->whereNull('category_id')
                    ->orderBy('id'),
            ])
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit($this->listLimit)
            ->get()
            ->map(fn (Order $order) => $this->mapOrder($order))
            ->filter(fn (array $order) => $order['lines'] !== [])
            ->values()
            ->all();

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('kind', Category::KIND_EXPENSE)
            ->orderBy('name')
            ->get(['id', 'name', 'kind'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
            ])
            ->values()
            ->all();

        return [
            'orders' => $orders,
            'ordersTruncated' => $totalMatching > $this->listLimit,
            'categories' => $categories,
            'filters' => [
                'q' => $query,
            ],
        ];
    }

    protected function baseDirtyOrdersQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereHas('merchant', function (Builder $builder): void {
                $builder->whereIn('normalized_name', self::QUEUE_MERCHANT_NORMALIZED_NAMES);
            })
            ->where(function (Builder $builder): void {
                $builder
                    ->where(function (Builder $walmart): void {
                        $walmart
                            ->whereHas('merchant', fn (Builder $m) => $m->where('normalized_name', 'walmart'))
                            ->whereHas('items', function (Builder $items): void {
                                $items->where(function (Builder $item): void {
                                    $item
                                        ->whereNull('product_id')
                                        ->orWhereHas('product', fn (Builder $product) => $product->whereNull('category_id'));
                                });
                            });
                    })
                    ->orWhere(function (Builder $amazon): void {
                        $amazon
                            ->whereHas('merchant', fn (Builder $m) => $m->where('normalized_name', 'amazon'))
                            ->whereHas('components', function (Builder $components): void {
                                $components
                                    ->where('type', 'product')
                                    ->whereNull('category_id');
                            });
                    });
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapOrder(Order $order): array
    {
        $normalized = $order->merchant?->normalized_name;
        $mode = $normalized === 'walmart' ? self::MODE_ITEMS : self::MODE_COMPONENTS;

        $lines = $mode === self::MODE_ITEMS
            ? $this->mapWalmartLines($order)
            : $this->mapAmazonLines($order);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'ordered_at' => optional($order->ordered_at)?->toDateString(),
            'total' => (float) $order->total,
            'status' => $order->status,
            'mode' => $mode,
            'merchant' => $order->merchant?->only(['id', 'name', 'normalized_name']),
            'lines' => $lines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mapWalmartLines(Order $order): array
    {
        $needsProduct = [];
        $needsCategory = [];

        foreach ($order->items as $item) {
            $line = $this->mapWalmartItem($item);

            if ($line === null) {
                continue;
            }

            if ($line['status'] === self::STATUS_NEEDS_PRODUCT) {
                $needsProduct[] = $line;
            } else {
                $needsCategory[] = $line;
            }
        }

        return [...$needsProduct, ...$needsCategory];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mapWalmartItem(OrderItem $item): ?array
    {
        if ($item->product_id === null) {
            return [
                'kind' => 'item',
                'id' => $item->id,
                'description' => $item->description,
                'sku' => $item->sku,
                'quantity' => (float) $item->quantity,
                'extended_price' => (float) $item->extended_price,
                'status' => self::STATUS_NEEDS_PRODUCT,
                'product' => null,
            ];
        }

        $product = $item->product;

        if ($product === null || $product->category_id !== null) {
            return null;
        }

        return [
            'kind' => 'item',
            'id' => $item->id,
            'description' => $item->description,
            'sku' => $item->sku,
            'quantity' => (float) $item->quantity,
            'extended_price' => (float) $item->extended_price,
            'status' => self::STATUS_NEEDS_CATEGORY,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mapAmazonLines(Order $order): array
    {
        return $order->components
            ->map(fn (OrderComponent $component) => [
                'kind' => 'component',
                'id' => $component->id,
                'description' => $component->description,
                'amount' => (float) $component->amount,
                'status' => self::STATUS_NEEDS_CATEGORY,
            ])
            ->values()
            ->all();
    }
}
