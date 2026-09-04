<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AccountBrowseService
{
    public function __construct(
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     accounts: list<array<string, mixed>>,
     *     bankCoverage: array{min: ?string, max: ?string}|null,
     *     filters: array{q: string}
     * }
     */
    public function index(int $userId, ?string $query = null): array
    {
        $query = trim((string) $query);

        $coverageByAccount = BankTransaction::query()
            ->where('user_id', $userId)
            ->whereNotNull('account_id')
            ->whereNotNull('posted_at')
            ->selectRaw('account_id, MIN(posted_at) as min_posted_at, MAX(posted_at) as max_posted_at, COUNT(*) as transaction_count')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $accountsQuery = Account::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->orderBy('id');

        if ($query !== '') {
            $accountsQuery->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('institution_name', 'like', "%{$query}%")
                    ->orWhere('last_four', 'like', "%{$query}%");
            });
        }

        $accounts = $accountsQuery->get()->map(function (Account $account) use ($coverageByAccount): array {
            $coverage = $coverageByAccount->get($account->id);
            $min = $coverage?->min_posted_at
                ? Carbon::parse($coverage->min_posted_at)->toDateString()
                : null;
            $max = $coverage?->max_posted_at
                ? Carbon::parse($coverage->max_posted_at)->toDateString()
                : null;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'account_type' => $account->account_type,
                'last_four' => $account->last_four,
                'is_off_book' => $account->isOffBook(),
                'transaction_count' => (int) ($coverage?->transaction_count ?? 0),
                'min_posted_at' => $min,
                'max_posted_at' => $max,
                'coverage_span_days' => $this->spanDays($min, $max),
            ];
        })->values()->all();

        return [
            'accounts' => $accounts,
            'bankCoverage' => $this->bankCoverage($userId),
            'filters' => [
                'q' => $query,
            ],
        ];
    }

    /**
     * @param  array{q?: ?string, from?: ?string, to?: ?string, page?: int}  $filters
     * @return array{
     *     account: array<string, mixed>,
     *     transactions: list<array<string, mixed>>,
     *     pagination: array{
     *         current_page: int,
     *         last_page: int,
     *         per_page: int,
     *         total: int,
     *         first_item: ?int,
     *         last_item: ?int
     *     },
     *     filters: array{q: string, from: string, to: string}
     * }
     */
    public function show(int $userId, Account $account, array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $from = $this->dateFilter($filters['from'] ?? null);
        $to = $this->dateFilter($filters['to'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $coverage = BankTransaction::query()
            ->where('user_id', $userId)
            ->where('account_id', $account->id)
            ->whereNotNull('posted_at')
            ->selectRaw('MIN(posted_at) as min_posted_at, MAX(posted_at) as max_posted_at, COUNT(*) as transaction_count')
            ->first();

        $min = $coverage?->min_posted_at
            ? Carbon::parse($coverage->min_posted_at)->toDateString()
            : null;
        $max = $coverage?->max_posted_at
            ? Carbon::parse($coverage->max_posted_at)->toDateString()
            : null;

        $transactionsQuery = BankTransaction::with([
            'merchant:id,name,normalized_name',
            'category:id,name,kind',
            'venmoActivities.cashedOutPayments',
        ])
            ->where('user_id', $userId)
            ->where('account_id', $account->id)
            ->when($q !== '', function (Builder $query) use ($q): void {
                $query->where(function (Builder $builder) use ($q): void {
                    $builder
                        ->where('description', 'like', "%{$q}%")
                        ->orWhere('amount', 'like', "%{$q}%")
                        ->orWhereHas('venmoActivities', function (Builder $venmo) use ($q): void {
                            $venmo
                                ->where('note', 'like', "%{$q}%")
                                ->orWhere('from_name', 'like', "%{$q}%")
                                ->orWhere('to_name', 'like', "%{$q}%")
                                ->orWhereHas('cashedOutPayments', function (Builder $payment) use ($q): void {
                                    $payment
                                        ->where('note', 'like', "%{$q}%")
                                        ->orWhere('from_name', 'like', "%{$q}%")
                                        ->orWhere('to_name', 'like', "%{$q}%");
                                });
                        });
                });
            })
            ->when($from !== null, fn (Builder $query) => $query->whereDate('posted_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('posted_at', '<=', $to))
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        $paginator = $transactionsQuery->paginate(
            perPage: $this->listLimit,
            page: $page,
        );

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'account_type' => $account->account_type,
                'last_four' => $account->last_four,
                'is_off_book' => $account->isOffBook(),
                'transaction_count' => (int) ($coverage?->transaction_count ?? 0),
                'min_posted_at' => $min,
                'max_posted_at' => $max,
                'coverage_span_days' => $this->spanDays($min, $max),
            ],
            'transactions' => $paginator->getCollection()->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'posted_at' => optional($transaction->posted_at)?->toDateString(),
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status,
                'classification' => $transaction->classification,
                'classification_source' => $transaction->classification_source,
                'classification_confidence' => $transaction->classification_confidence !== null
                    ? (float) $transaction->classification_confidence
                    : null,
                'card_last_four' => $transaction->card_last_four,
                'merchant' => $transaction->merchant?->only(['id', 'name', 'normalized_name']),
                'category' => $transaction->category ? [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'kind' => $transaction->category->kind,
                ] : null,
                'venmo_summary' => $transaction->venmoSummary(),
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'first_item' => $paginator->firstItem(),
                'last_item' => $paginator->lastItem(),
            ],
            'filters' => [
                'q' => $q,
                'from' => $from ?? '',
                'to' => $to ?? '',
            ],
        ];
    }

    /**
     * @return array{min: ?string, max: ?string}|null
     */
    public function bankCoverage(int $userId): ?array
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

    protected function spanDays(?string $min, ?string $max): ?int
    {
        if ($min === null || $max === null) {
            return null;
        }

        return (int) abs(Carbon::parse($min)->startOfDay()->diffInDays(Carbon::parse($max)->startOfDay(), false));
    }

    protected function dateFilter(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->toDateString();
    }
}
