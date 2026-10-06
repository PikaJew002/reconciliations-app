<?php

namespace App\Services\Reconciliation;

use App\Models\ComponentTaxRule;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\TransactionAllocation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderTaxReconciler
{
    public function __construct(
        protected ProductMatchingService $productMatching,
    ) {}

    /**
     * @return array{
     *     rate: string,
     *     tax_cents: int,
     *     lines: list<array{
     *         id: int,
     *         type: string,
     *         description: string,
     *         price_cents: int,
     *         tax_status: ?bool,
     *         selected: bool
     *     }>
     * }|null
     */
    public function present(Order $order): ?array
    {
        $order->load(['user', 'components.orderItem.product']);

        $lump = $this->lumpTax($order);

        if ($lump === null) {
            return null;
        }

        $rules = $this->rulesFor($order);
        $lines = [];

        foreach ($this->candidates($order) as $component) {
            $status = $this->knownStatus($component, $rules);
            $lines[] = [
                'id' => $component->id,
                'type' => $component->type,
                'description' => $component->description,
                'price_cents' => SalesTaxCalculator::cents($component->amount),
                'tax_status' => $status,
                'selected' => $status !== false,
            ];
        }

        return [
            'rate' => (string) ($order->user?->sales_tax_rate ?? '0.06000'),
            'tax_cents' => SalesTaxCalculator::cents($lump->amount),
            'lines' => $lines,
        ];
    }

    public function tryAutoClose(Order $order): bool
    {
        $order->unsetRelation('components');
        $order->load(['user', 'merchant', 'components.orderItem.product', 'components.allocations']);

        $lump = $this->lumpTax($order);

        if ($lump === null || $order->user === null) {
            return false;
        }

        $rules = $this->rulesFor($order);
        $selectedIds = [];

        foreach ($this->candidates($order) as $component) {
            $status = $this->knownStatus($component, $rules);

            if ($status === null) {
                return false;
            }

            if ($status) {
                $selectedIds[] = $component->id;
            }
        }

        $rate = (string) $order->user->sales_tax_rate;

        if (! $this->selectionMatches($order, $selectedIds, $rate)) {
            return false;
        }

        try {
            $this->apply($order, $order->user, $rate, $selectedIds, manual: false);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $selectedIds
     */
    public function apply(Order $order, User $user, string|int|float $rate, array $selectedIds, bool $manual = true): void
    {
        $normalizedRate = SalesTaxCalculator::normalizeRate($rate);
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));

        DB::transaction(function () use ($order, $user, $normalizedRate, $selectedIds, $manual): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('user', $user);
            $locked->load(['merchant', 'components.orderItem.product', 'components.allocations']);

            $lump = $this->lumpTax($locked);

            if ($lump === null) {
                throw ValidationException::withMessages([
                    'component_ids' => 'This order has no sales tax line to reconcile.',
                ]);
            }

            $candidates = $this->candidates($locked);
            $candidateIds = $candidates->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $unknown = array_values(array_diff($selectedIds, $candidateIds));

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'component_ids' => 'One of the selected lines cannot be taxed.',
                ]);
            }

            if (! $this->selectionMatches($locked, $selectedIds, $normalizedRate)) {
                throw ValidationException::withMessages([
                    'component_ids' => 'The selected lines do not add up to the sales tax.',
                ]);
            }

            if ($manual) {
                $user->update(['sales_tax_rate' => $normalizedRate]);
            }

            $this->rememberSelections($locked, $candidates, $selectedIds);
            $this->replaceLumpTax($locked, $lump, $candidates, $selectedIds, $manual);
        });
    }

    /**
     * @param  list<int>  $selectedIds
     */
    protected function selectionMatches(Order $order, array $selectedIds, string|int|float $rate): bool
    {
        $lump = $this->lumpTax($order);

        if ($lump === null) {
            return false;
        }

        $selected = $this->candidates($order)->whereIn('id', $selectedIds);
        $priceCents = (int) $selected->sum(
            fn (OrderComponent $component): int => SalesTaxCalculator::cents($component->amount),
        );

        return SalesTaxCalculator::roundedCents($priceCents, $rate) === SalesTaxCalculator::cents($lump->amount);
    }

    /**
     * @param  Collection<int, OrderComponent>  $candidates
     * @param  list<int>  $selectedIds
     */
    protected function rememberSelections(Order $order, Collection $candidates, array $selectedIds): void
    {
        $productSelections = [];

        foreach ($candidates as $component) {
            $selected = in_array($component->id, $selectedIds, true);

            if ($component->type === 'product') {
                $this->rememberProduct($component, $selected, $productSelections);

                continue;
            }

            if ($order->merchant_id === null) {
                continue;
            }

            ComponentTaxRule::query()->updateOrCreate(
                [
                    'user_id' => $order->user_id,
                    'merchant_id' => $order->merchant_id,
                    'type' => $component->type,
                    'normalized_description' => ComponentTaxRule::normalize($component->description),
                ],
                ['is_taxable' => $selected],
            );
        }
    }

    /**
     * @param  array<int, bool>  $productSelections
     */
    protected function rememberProduct(OrderComponent $component, bool $selected, array &$productSelections): void
    {
        $item = $component->orderItem;

        if (! $item instanceof OrderItem) {
            return;
        }

        $item->update(['taxable' => $selected]);

        $linked = $this->productMatching->linkOrCreateForItem($item->fresh());

        if ($linked === null) {
            return;
        }

        $productId = (int) $linked['product']->id;

        if (array_key_exists($productId, $productSelections) && $productSelections[$productId] !== $selected) {
            throw ValidationException::withMessages([
                'component_ids' => 'The same product is both taxable and exempt on this order.',
            ]);
        }

        $productSelections[$productId] = $selected;
        $linked['product']->update(['is_taxable' => $selected]);
    }

    /**
     * @param  Collection<int, OrderComponent>  $candidates
     * @param  list<int>  $selectedIds
     */
    protected function replaceLumpTax(
        Order $order,
        OrderComponent $lump,
        Collection $candidates,
        array $selectedIds,
        bool $manual,
    ): void {
        $allocations = $lump->allocations()->orderBy('id')->get();

        if ($allocations->contains(
            fn (TransactionAllocation $allocation): bool => $allocation->allocation_type === TransactionAllocation::TYPE_REFUND,
        )) {
            throw ValidationException::withMessages([
                'component_ids' => 'This sales tax line has a refund allocation and cannot be split.',
            ]);
        }

        $selected = $candidates
            ->whereIn('id', $selectedIds)
            ->sortBy('id')
            ->values();
        $partials = $this->createPartials($order, $lump, $selected, $manual);

        $capacities = [];

        foreach ($partials as $partial) {
            $capacities[$partial->id] = SalesTaxCalculator::cents($partial->amount);
        }

        foreach ($allocations as $allocation) {
            $remaining = SalesTaxCalculator::cents($allocation->allocated_amount);

            foreach ($partials as $partial) {
                if ($remaining < 1) {
                    break;
                }

                $room = $capacities[$partial->id];

                if ($room < 1) {
                    continue;
                }

                $take = min($remaining, $room);

                TransactionAllocation::query()->create([
                    'bank_transaction_id' => $allocation->bank_transaction_id,
                    'order_component_id' => $partial->id,
                    'allocated_amount' => number_format($take / 100, 2, '.', ''),
                    'allocation_type' => $allocation->allocation_type,
                    'match_confidence' => $allocation->match_confidence,
                    'notes' => $allocation->notes,
                    'metadata' => $allocation->metadata ?? [],
                ]);

                $capacities[$partial->id] -= $take;
                $remaining -= $take;
            }

            if ($remaining >= 1) {
                throw ValidationException::withMessages([
                    'component_ids' => 'The sales tax allocation could not be moved onto the taxable lines.',
                ]);
            }

            $allocation->delete();
        }

        $lump->delete();
    }

    /**
     * @param  Collection<int, OrderComponent>  $selected
     * @return list<OrderComponent>
     */
    protected function createPartials(Order $order, OrderComponent $lump, Collection $selected, bool $manual): array
    {
        $taxCents = SalesTaxCalculator::cents($lump->amount);
        $baseCents = (int) $selected->sum(
            fn (OrderComponent $component): int => SalesTaxCalculator::cents($component->amount),
        );

        if ($baseCents <= 0) {
            throw ValidationException::withMessages([
                'component_ids' => 'The selected lines do not add up to the sales tax.',
            ]);
        }

        $shares = [];

        foreach ($selected as $component) {
            $cents = SalesTaxCalculator::cents($component->amount);
            $shares[] = [
                'component' => $component,
                'cents' => intdiv($cents * $taxCents, $baseCents),
                'remainder' => ($cents * $taxCents) % $baseCents,
            ];
        }

        $assigned = array_sum(array_column($shares, 'cents'));
        $left = $taxCents - $assigned;

        usort($shares, function (array $leftShare, array $rightShare): int {
            if ($leftShare['remainder'] === $rightShare['remainder']) {
                return $leftShare['component']->id <=> $rightShare['component']->id;
            }

            return $rightShare['remainder'] <=> $leftShare['remainder'];
        });

        foreach ($shares as $index => $share) {
            if ($left <= 0) {
                break;
            }

            $shares[$index]['cents']++;
            $left--;
        }

        $partials = [];

        foreach ($shares as $share) {
            if ($share['cents'] === 0) {
                continue;
            }

            /** @var OrderComponent $source */
            $source = $share['component'];
            $partials[] = OrderComponent::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $source->type === 'product' ? $source->order_item_id : null,
                'type' => 'tax',
                'description' => $this->partialDescription($source),
                'amount' => number_format($share['cents'] / 100, 2, '.', ''),
                'category_id' => $source->category_id,
                'category_confidence' => $source->category_id !== null ? $source->category_confidence : null,
                'is_user_modified' => $manual,
                'metadata' => [
                    'tax_allocation' => true,
                    'allocated_component_id' => $source->id,
                ],
            ]);
        }

        return $partials;
    }

    protected function partialDescription(OrderComponent $source): string
    {
        $description = 'Sales Tax · '.$source->description;

        if (mb_strlen($description) <= 255) {
            return $description;
        }

        return mb_substr($description, 0, 255);
    }

    protected function lumpTax(Order $order): ?OrderComponent
    {
        $components = $order->relationLoaded('components')
            ? $order->components
            : $order->components()->get();

        return $components->first(function (OrderComponent $component): bool {
            if ($component->type !== 'tax' || $component->order_item_id !== null) {
                return false;
            }

            return empty($component->metadata['tax_allocation']);
        });
    }

    /**
     * @return Collection<int, OrderComponent>
     */
    protected function candidates(Order $order): Collection
    {
        $components = $order->relationLoaded('components')
            ? $order->components
            : $order->components()->get();

        return $components
            ->filter(fn (OrderComponent $component): bool => in_array($component->type, ['product', 'delivery', 'fee'], true))
            ->values();
    }

    /**
     * @return Collection<string, ComponentTaxRule>
     */
    protected function rulesFor(Order $order): Collection
    {
        if ($order->merchant_id === null) {
            return collect();
        }

        return ComponentTaxRule::query()
            ->where('user_id', $order->user_id)
            ->where('merchant_id', $order->merchant_id)
            ->get()
            ->keyBy(fn (ComponentTaxRule $rule): string => $rule->type.'|'.$rule->normalized_description);
    }

    /**
     * @param  Collection<string, ComponentTaxRule>  $rules
     */
    protected function knownStatus(OrderComponent $component, Collection $rules): ?bool
    {
        if ($component->type === 'product') {
            return $component->orderItem?->product?->is_taxable;
        }

        $rule = $rules->get($component->type.'|'.ComponentTaxRule::normalize($component->description));

        if ($rule === null) {
            return null;
        }

        return (bool) $rule->is_taxable;
    }
}
