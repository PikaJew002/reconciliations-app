<?php

namespace App\Services\Merchants;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MerchantBrowseService
{
    public function __construct(
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     otherMerchants: list<array<string, mixed>>,
     *     filters: array{q: string}
     * }
     */
    public function index(int $userId, ?string $query = null): array
    {
        $query = trim((string) $query);

        $coverageByMerchant = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('merchant_id')
            ->whereNotNull('posted_at')
            ->selectRaw('merchant_id, MIN(posted_at) as min_posted_at, MAX(posted_at) as max_posted_at, COUNT(*) as transaction_count')
            ->groupBy('merchant_id')
            ->get()
            ->keyBy('merchant_id');

        $merchantIds = $coverageByMerchant->keys()->all();

        if ($merchantIds === []) {
            return [
                'otherMerchants' => [],
                'filters' => [
                    'q' => $query,
                ],
            ];
        }

        $excludedNormalizedNames = collect(OrderBrowseService::BROWSABLE_MERCHANTS)
            ->pluck('normalized_name')
            ->all();

        $merchantsQuery = Merchant::query()
            ->where('user_id', $userId)
            ->whereIn('id', $merchantIds)
            ->where('supports_order_import', false)
            ->whereNotIn('normalized_name', $excludedNormalizedNames)
            ->orderBy('name')
            ->orderBy('id');

        if ($query !== '') {
            $merchantsQuery->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('normalized_name', 'like', "%{$query}%")
                    ->orWhere('type', 'like', "%{$query}%");
            });
        }

        $otherMerchants = $merchantsQuery->get()->map(function (Merchant $merchant) use ($coverageByMerchant): array {
            $coverage = $coverageByMerchant->get($merchant->id);
            $min = $coverage?->min_posted_at
                ? Carbon::parse($coverage->min_posted_at)->toDateString()
                : null;
            $max = $coverage?->max_posted_at
                ? Carbon::parse($coverage->max_posted_at)->toDateString()
                : null;

            return [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'normalized_name' => $merchant->normalized_name,
                'type' => $merchant->type,
                'transaction_count' => (int) ($coverage?->transaction_count ?? 0),
                'min_posted_at' => $min,
                'max_posted_at' => $max,
                'coverage_span_days' => $this->spanDays($min, $max),
            ];
        })->values()->all();

        return [
            'otherMerchants' => $otherMerchants,
            'filters' => [
                'q' => $query,
            ],
        ];
    }

    /**
     * @return array{
     *     merchant: array<string, mixed>,
     *     transactions: list<array<string, mixed>>,
     *     transactionsTruncated: bool,
     *     filters: array{q: string}
     * }|null
     */
    public function show(int $userId, Merchant $merchant, ?string $query = null): ?array
    {
        if ($merchant->user_id !== $userId) {
            return null;
        }

        if ($this->isOrderImportRetailer($merchant)) {
            return null;
        }

        $hasActivity = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $merchant->id)
            ->exists();

        if (! $hasActivity) {
            return null;
        }

        $query = trim((string) $query);

        $coverage = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $merchant->id)
            ->whereNotNull('posted_at')
            ->selectRaw('MIN(posted_at) as min_posted_at, MAX(posted_at) as max_posted_at, COUNT(*) as transaction_count')
            ->first();

        $min = $coverage?->min_posted_at
            ? Carbon::parse($coverage->min_posted_at)->toDateString()
            : null;
        $max = $coverage?->max_posted_at
            ? Carbon::parse($coverage->max_posted_at)->toDateString()
            : null;

        $transactionsQuery = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('merchant_id', $merchant->id)
            ->with('account:id,name,institution_name,last_four')
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        if ($query !== '') {
            $transactionsQuery->where(function ($builder) use ($query): void {
                $builder
                    ->where('description', 'like', "%{$query}%")
                    ->orWhere('amount', 'like', "%{$query}%");
            });
        }

        $totalMatching = (clone $transactionsQuery)->count();

        /** @var Collection<int, BankTransaction> $transactions */
        $transactions = $transactionsQuery
            ->limit($this->listLimit)
            ->get();

        return [
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'normalized_name' => $merchant->normalized_name,
                'type' => $merchant->type,
                'transaction_count' => (int) ($coverage?->transaction_count ?? 0),
                'min_posted_at' => $min,
                'max_posted_at' => $max,
                'coverage_span_days' => $this->spanDays($min, $max),
            ],
            'transactions' => $transactions->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'posted_at' => optional($transaction->posted_at)?->toDateString(),
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status,
                'card_last_four' => $transaction->card_last_four,
                'account' => $transaction->account?->only(['id', 'name', 'institution_name', 'last_four']),
            ])->values()->all(),
            'transactionsTruncated' => $totalMatching > $this->listLimit,
            'filters' => [
                'q' => $query,
            ],
        ];
    }

    protected function isOrderImportRetailer(Merchant $merchant): bool
    {
        if ($merchant->supports_order_import) {
            return true;
        }

        $excludedNormalizedNames = collect(OrderBrowseService::BROWSABLE_MERCHANTS)
            ->pluck('normalized_name')
            ->all();

        return in_array($merchant->normalized_name, $excludedNormalizedNames, true);
    }

    protected function spanDays(?string $min, ?string $max): ?int
    {
        if ($min === null || $max === null) {
            return null;
        }

        return (int) abs(Carbon::parse($min)->startOfDay()->diffInDays(Carbon::parse($max)->startOfDay(), false));
    }
}
