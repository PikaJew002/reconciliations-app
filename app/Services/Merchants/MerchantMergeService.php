<?php

namespace App\Services\Merchants;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\Product;
use App\Models\TransactionCategorizationRule;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantMergeService
{
    public function __construct(
        protected MerchantBrowseService $browse,
        protected MerchantMatcher $matcher,
    ) {}

    /**
     * @param  list<int>  $merchantIds
     */
    public function merge(int $userId, array $merchantIds): Merchant
    {
        $ids = collect($merchantIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->count() < 2) {
            throw new InvalidArgumentException('At least two merchants are required to merge.');
        }

        /** @var Collection<int, Merchant> $merchants */
        $merchants = Merchant::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($merchants->count() !== $ids->count()) {
            throw new NotFoundHttpException;
        }

        foreach ($merchants as $merchant) {
            if (! $this->browse->isBrowsable($userId, $merchant)) {
                throw new NotFoundHttpException;
            }
        }

        $survivor = $merchants->first();
        $absorbedIds = $merchants->skip(1)->pluck('id')->all();

        DB::transaction(function () use ($userId, $survivor, $absorbedIds): void {
            $this->reassignMatchingRules($userId, $survivor, $absorbedIds);
            $this->remapSimpleMerchantIds($survivor->id, $absorbedIds);
            $this->remapProducts($userId, $survivor->id, $absorbedIds);
            $this->remapOrders($survivor->id, $absorbedIds);

            Merchant::query()->whereIn('id', $absorbedIds)->delete();
        });

        $this->matcher->matchForUser($userId);

        return $survivor->fresh() ?? $survivor;
    }

    /**
     * @param  list<int>  $absorbedIds
     */
    protected function reassignMatchingRules(int $userId, Merchant $survivor, array $absorbedIds): void
    {
        $existingKeys = MerchantMatchingRule::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $survivor->id)
            ->get()
            ->map(fn (MerchantMatchingRule $rule): string => $this->ruleKey($rule))
            ->all();

        $incoming = MerchantMatchingRule::query()
            ->whereIn('merchant_id', $absorbedIds)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        $seenIncoming = [];

        foreach ($incoming as $rule) {
            $key = $this->ruleKey($rule);

            if (in_array($key, $existingKeys, true) || in_array($key, $seenIncoming, true)) {
                $rule->delete();

                continue;
            }

            $rule->update(['merchant_id' => $survivor->id]);
            $seenIncoming[] = $key;
        }
    }

    /**
     * @param  list<int>  $absorbedIds
     */
    protected function remapSimpleMerchantIds(int $survivorId, array $absorbedIds): void
    {
        foreach ([
            BankTransaction::class,
            PendingSpend::class,
            PlannedTemplate::class,
            PlannedOccurrence::class,
            TransactionCategorizationRule::class,
        ] as $model) {
            $model::query()
                ->whereIn('merchant_id', $absorbedIds)
                ->update(['merchant_id' => $survivorId]);
        }
    }

    /**
     * @param  list<int>  $absorbedIds
     */
    protected function remapProducts(int $userId, int $survivorId, array $absorbedIds): void
    {
        $survivorProducts = Product::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $survivorId)
            ->get();

        $byName = $survivorProducts->keyBy('normalized_name');
        $bySku = $survivorProducts
            ->filter(fn (Product $product): bool => filled($product->sku))
            ->keyBy('sku');

        /** @var Collection<int, Product> $absorbedProducts */
        $absorbedProducts = Product::query()
            ->whereIn('merchant_id', $absorbedIds)
            ->orderBy('id')
            ->get();

        foreach ($absorbedProducts as $product) {
            $collision = $byName->get($product->normalized_name);

            if ($collision === null && filled($product->sku)) {
                $collision = $bySku->get($product->sku);
            }

            if ($collision instanceof Product) {
                OrderItem::query()
                    ->where('product_id', $product->id)
                    ->update(['product_id' => $collision->id]);
                $product->delete();

                continue;
            }

            $product->update(['merchant_id' => $survivorId]);
            $byName->put($product->normalized_name, $product);

            if (filled($product->sku)) {
                $bySku->put($product->sku, $product);
            }
        }
    }

    /**
     * @param  list<int>  $absorbedIds
     */
    protected function remapOrders(int $survivorId, array $absorbedIds): void
    {
        /** @var Collection<int, string> $survivorOrderNumbers */
        $survivorOrderNumbers = Order::query()
            ->where('merchant_id', $survivorId)
            ->pluck('order_number');

        Order::query()
            ->whereIn('merchant_id', $absorbedIds)
            ->orderBy('id')
            ->each(function (Order $order) use ($survivorId, $survivorOrderNumbers): void {
                if ($survivorOrderNumbers->contains($order->order_number)) {
                    $order->delete();

                    return;
                }

                $order->update(['merchant_id' => $survivorId]);
                $survivorOrderNumbers->push($order->order_number);
            });
    }

    protected function ruleKey(MerchantMatchingRule $rule): string
    {
        return $rule->match_mode.'|'.$rule->pattern;
    }
}
