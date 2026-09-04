<?php

namespace App\Services\Review;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Order;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\ReimbursementGroup;
use App\Models\TransactionAllocation;
use App\Services\Reporting\CategorySpendQuery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ReviewSlideBuilder
{
    public const PASS_DEFAULT = 'default';

    public const PASS_ALL = 'all';

    /**
     * Order-level fees. They stay in week spend and the order total,
     * but they are not their own Sunday slides.
     *
     * @var list<string>
     */
    public const HIDDEN_COMPONENT_TYPES = ['tax', 'delivery', 'tip'];

    public function __construct(
        protected CategorySpendQuery $spendQuery,
    ) {}

    /**
     * @return array{
     *     slides: list<array<string, mixed>>,
     *     expected_bills: ?array<string, mixed>,
     *     week_spend: float
     * }
     */
    public function build(
        int $userId,
        CarbonInterface $from,
        CarbonInterface $to,
        string $pass = self::PASS_DEFAULT,
    ): array {
        if (! in_array($pass, [self::PASS_DEFAULT, self::PASS_ALL], true)) {
            $pass = self::PASS_DEFAULT;
        }

        $events = $this->spendQuery->spendEventsForUser($userId, $from, $to);
        $allocatedBankIds = $this->allocatedBankTransactionIds($userId);
        $assignedBillTransactionIds = $this->assignedBillTransactionIds($userId);
        $categories = $this->categoriesForUser($userId);
        $pendingById = $this->pendingById($userId, $events);
        $ordersById = $this->ordersById($userId, $events);
        $banksById = $this->banksById($userId, $events);
        $groupsById = $this->groupsById($userId, $events);

        $expectedBillEvents = [];
        $walkEvents = [];

        foreach ($events as $event) {
            if (
                $event['source'] === 'bank'
                && $event['bank_transaction_id'] !== null
                && isset($allocatedBankIds[(int) $event['bank_transaction_id']])
            ) {
                continue;
            }

            if (
                $event['source'] === 'bank'
                && $event['bank_transaction_id'] !== null
                && isset($assignedBillTransactionIds[(int) $event['bank_transaction_id']])
            ) {
                $expectedBillEvents[] = $event;

                continue;
            }

            $walkEvents[] = $event;
        }

        $expectedBillsSlide = $this->expectedBillsSlide($expectedBillEvents, $categories, $banksById);
        $walkSlides = $this->walkSlides(
            $walkEvents,
            $categories,
            $pendingById,
            $ordersById,
            $banksById,
            $groupsById,
        );

        $weekSpend = round((float) collect($walkEvents)->sum('amount'), 2);

        $slides = $pass === self::PASS_ALL && $expectedBillsSlide !== null
            ? [$expectedBillsSlide, ...$walkSlides]
            : $walkSlides;

        return [
            'slides' => $slides,
            'expected_bills' => $expectedBillsSlide,
            'week_spend' => $weekSpend,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, PendingSpend>  $pendingById
     * @param  Collection<int, Order>  $ordersById
     * @param  Collection<int, BankTransaction>  $banksById
     * @param  Collection<int, ReimbursementGroup>  $groupsById
     * @return list<array<string, mixed>>
     */
    protected function walkSlides(
        array $events,
        Collection $categories,
        Collection $pendingById,
        Collection $ordersById,
        Collection $banksById,
        Collection $groupsById,
    ): array {
        $orderEvents = [];
        $otherEvents = [];

        foreach ($events as $event) {
            if ($event['source'] === 'order_component' && $event['order_id'] !== null) {
                $orderEvents[(int) $event['order_id']][] = $event;

                continue;
            }

            $otherEvents[] = $event;
        }

        $groups = [];

        foreach ($otherEvents as $event) {
            $slide = $this->eventSlide($event, $categories, $pendingById, $banksById, $groupsById);

            if ($slide === null) {
                continue;
            }

            $groups[] = [
                'attention' => $this->isAttention($slide),
                'date' => $slide['date'],
                'id' => $slide['id'],
                'slides' => [$slide],
            ];
        }

        foreach ($orderEvents as $orderId => $components) {
            $order = $ordersById->get($orderId);
            $orderSlides = $this->orderSlides($orderId, $components, $order, $categories);
            $attention = collect($orderSlides)->contains(
                fn (array $slide): bool => $this->isAttention($slide),
            );

            $groups[] = [
                'attention' => $attention,
                'date' => $orderSlides[0]['date'] ?? $components[0]['date'],
                'id' => 'order:'.$orderId,
                'slides' => $orderSlides,
            ];
        }

        usort($groups, function (array $left, array $right): int {
            $attention = ((int) $right['attention']) <=> ((int) $left['attention']);

            if ($attention !== 0) {
                return $attention;
            }

            $date = strcmp((string) $left['date'], (string) $right['date']);

            if ($date !== 0) {
                return $date;
            }

            return strcmp((string) $left['id'], (string) $right['id']);
        });

        $slides = [];

        foreach ($groups as $group) {
            foreach ($group['slides'] as $slide) {
                $slides[] = $slide;
            }
        }

        return $slides;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    protected function orderSlides(
        int $orderId,
        array $components,
        ?Order $order,
        Collection $categories,
    ): array {
        $visible = array_values(array_filter(
            $components,
            fn (array $event): bool => ! in_array(
                $event['component_type'] ?? null,
                self::HIDDEN_COMPONENT_TYPES,
                true,
            ),
        ));
        $amount = round((float) collect($components)->sum('amount'), 2);
        $date = $components[0]['date'];
        $merchantName = $order?->merchant?->name ?? 'Order';
        $uncategorized = collect($visible)->contains(
            fn (array $event): bool => $event['category_id'] === null,
        );

        $parent = $this->slide(
            id: 'order:'.$orderId,
            kind: 'order',
            date: $date,
            amount: $amount,
            name: $merchantName,
            category: null,
            classification: BankTransaction::CLASSIFICATION_EXPENSE,
            posted: true,
            needsReview: false,
            uncategorized: $uncategorized,
            parentId: null,
            categorizable: false,
            allowedKinds: [],
            sourceId: $orderId,
            badge: null,
        );

        $children = [];

        foreach ($visible as $event) {
            $componentId = (int) $event['order_component_id'];
            $category = $this->categoryPayload($categories, $event['category_id']);

            $children[] = $this->slide(
                id: 'order_component:'.$componentId,
                kind: 'order_component',
                date: $date,
                amount: round((float) $event['amount'], 2),
                name: $event['name'] ?: $merchantName,
                category: $category,
                classification: BankTransaction::CLASSIFICATION_EXPENSE,
                posted: true,
                needsReview: false,
                uncategorized: $event['category_id'] === null,
                parentId: 'order:'.$orderId,
                categorizable: true,
                allowedKinds: [Category::KIND_EXPENSE],
                sourceId: $componentId,
                badge: $merchantName,
            );
        }

        return [$parent, ...$children];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, PendingSpend>  $pendingById
     * @param  Collection<int, BankTransaction>  $banksById
     * @param  Collection<int, ReimbursementGroup>  $groupsById
     * @return array<string, mixed>|null
     */
    protected function eventSlide(
        array $event,
        Collection $categories,
        Collection $pendingById,
        Collection $banksById,
        Collection $groupsById,
    ): ?array {
        $category = $this->categoryPayload($categories, $event['category_id'] ?? null);

        if ($event['source'] === 'bank') {
            $transactionId = (int) $event['bank_transaction_id'];
            $transaction = $banksById->get($transactionId);
            $name = $transaction?->merchant?->name ?: ($event['name'] ?: 'Bank charge');

            return $this->slide(
                id: 'bank:'.$transactionId,
                kind: 'bank',
                date: $event['date'],
                amount: round((float) $event['amount'], 2),
                name: $name,
                category: $category,
                classification: $event['classification'],
                posted: true,
                needsReview: false,
                uncategorized: $event['category_id'] === null,
                parentId: null,
                categorizable: true,
                allowedKinds: [Category::KIND_BILL, Category::KIND_EXPENSE],
                sourceId: $transactionId,
                badge: null,
            );
        }

        if ($event['source'] === 'pending') {
            $pendingId = (int) $event['pending_spend_id'];
            $pending = $pendingById->get($pendingId);
            $name = $event['name']
                ?: ($pending?->merchant?->name ?: 'Pending spend');
            $needsReview = $pending?->status === PendingSpend::STATUS_NEEDS_REVIEW;

            return $this->slide(
                id: 'pending:'.$pendingId,
                kind: 'pending',
                date: $event['date'],
                amount: round((float) $event['amount'], 2),
                name: $name,
                category: $category,
                classification: $event['classification'],
                posted: false,
                needsReview: $needsReview,
                uncategorized: $event['category_id'] === null,
                parentId: null,
                categorizable: true,
                allowedKinds: [Category::KIND_BILL, Category::KIND_EXPENSE],
                sourceId: $pendingId,
                badge: 'Not posted',
            );
        }

        if ($event['source'] === 'reimbursement') {
            $groupId = (int) $event['reimbursement_group_id'];
            $group = $groupsById->get($groupId);
            $expenseTotal = $group?->expenseTotal() ?? 0.0;
            $reimbursementTotal = $group?->reimbursementTotal() ?? 0.0;
            $net = $group !== null
                ? $group->net()
                : round((float) $event['amount'], 2);

            return $this->slide(
                id: 'reimbursement:'.$groupId,
                kind: 'reimbursement',
                date: $event['date'],
                amount: round((float) $event['amount'], 2),
                name: $event['name'] ?: 'Reimbursement remainder',
                category: $category,
                classification: $event['classification'],
                posted: true,
                needsReview: false,
                uncategorized: $event['category_id'] === null,
                parentId: null,
                categorizable: false,
                allowedKinds: [],
                sourceId: $groupId,
                badge: 'Reimbursement remainder',
                extra: [
                    'expense_total' => $expenseTotal,
                    'reimbursement_total' => $reimbursementTotal,
                    'net' => $net,
                    'items' => $this->reimbursementItems($group),
                ],
            );
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  Collection<int, Category>  $categories
     * @param  Collection<int, BankTransaction>  $banksById
     * @return array<string, mixed>|null
     */
    protected function expectedBillsSlide(
        array $events,
        Collection $categories,
        Collection $banksById,
    ): ?array {
        if ($events === []) {
            return null;
        }

        $items = [];

        foreach ($events as $event) {
            $transaction = $banksById->get((int) $event['bank_transaction_id']);
            $category = $this->categoryPayload($categories, $event['category_id'] ?? null);

            $items[] = [
                'name' => $transaction?->merchant?->name ?: ($event['name'] ?: 'Bill'),
                'amount' => round((float) $event['amount'], 2),
                'date' => $event['date'],
                'category' => $category,
            ];
        }

        return $this->slide(
            id: 'expected_bills',
            kind: 'expected_bills',
            date: $events[0]['date'],
            amount: round((float) collect($items)->sum('amount'), 2),
            name: 'Expected bills',
            category: null,
            classification: BankTransaction::CLASSIFICATION_BILL,
            posted: true,
            needsReview: false,
            uncategorized: false,
            parentId: null,
            categorizable: false,
            allowedKinds: [],
            sourceId: null,
            badge: 'Already planned',
            extra: ['items' => $items],
        );
    }

    /**
     * @param  list<string>  $allowedKinds
     * @param  array<string, mixed>|null  $category
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function slide(
        string $id,
        string $kind,
        string $date,
        float $amount,
        string $name,
        ?array $category,
        string $classification,
        bool $posted,
        bool $needsReview,
        bool $uncategorized,
        ?string $parentId,
        bool $categorizable,
        array $allowedKinds,
        ?int $sourceId,
        ?string $badge,
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'date' => $date,
            'amount' => $amount,
            'name' => $name,
            'category' => $category,
            'classification' => $classification,
            'posted' => $posted,
            'needs_review' => $needsReview,
            'uncategorized' => $uncategorized,
            'parent_id' => $parentId,
            'categorizable' => $categorizable,
            'allowed_kinds' => $allowedKinds,
            'source_id' => $sourceId,
            'badge' => $badge,
            ...$extra,
        ];
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array{id: int, name: string, kind: string, color: ?string}|null
     */
    protected function categoryPayload(Collection $categories, mixed $categoryId): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        $category = $categories->get((int) $categoryId);

        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'kind' => $category->kind,
            'color' => $category->color,
        ];
    }

    /**
     * @param  array<string, mixed>  $slide
     */
    protected function isAttention(array $slide): bool
    {
        return $slide['uncategorized'] || $slide['needs_review'];
    }

    /**
     * @return array<int, true>
     */
    protected function allocatedBankTransactionIds(int $userId): array
    {
        $ids = TransactionAllocation::query()
            ->whereHas(
                'bankTransaction',
                fn ($query) => $query->where('user_id', $userId),
            )
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @return array<int, true>
     */
    protected function assignedBillTransactionIds(int $userId): array
    {
        $assignedBillTemplateIds = PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->with('assignedBills:id')
            ->get()
            ->flatMap(fn (PlannedTemplate $paycheck) => $paycheck->assignedBills->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($assignedBillTemplateIds === []) {
            return [];
        }

        $ids = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->whereIn('template_id', $assignedBillTemplateIds)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @return Collection<int, Category>
     */
    protected function categoriesForUser(int $userId): Collection
    {
        return Category::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return Collection<int, PendingSpend>
     */
    protected function pendingById(int $userId, array $events): Collection
    {
        $ids = collect($events)
            ->pluck('pending_spend_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return PendingSpend::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->with('merchant:id,name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return Collection<int, Order>
     */
    protected function ordersById(int $userId, array $events): Collection
    {
        $ids = collect($events)
            ->pluck('order_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->with('merchant:id,name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return Collection<int, BankTransaction>
     */
    protected function banksById(int $userId, array $events): Collection
    {
        $ids = collect($events)
            ->pluck('bank_transaction_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->with('merchant:id,name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return Collection<int, ReimbursementGroup>
     */
    protected function groupsById(int $userId, array $events): Collection
    {
        $ids = collect($events)
            ->pluck('reimbursement_group_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return ReimbursementGroup::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->with(['legs.bankTransaction.merchant:id,name'])
            ->get()
            ->keyBy('id');
    }

    /**
     * @return list<array{role: string, name: string, amount: float}>
     */
    protected function reimbursementItems(?ReimbursementGroup $group): array
    {
        if ($group === null) {
            return [];
        }

        return $group->legs
            ->map(function ($leg): array {
                $transaction = $leg->bankTransaction;

                return [
                    'role' => $leg->role,
                    'name' => $transaction?->merchant?->name
                        ?: ($transaction?->description ?: 'Transaction'),
                    'amount' => round((float) $leg->amount, 2),
                ];
            })
            ->values()
            ->all();
    }
}
