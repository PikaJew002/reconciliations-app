<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\VenmoActivity;
use App\Services\Imports\ImportBatchRevertService;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImportBatchController extends Controller
{
    public function showForAccount(Request $request, Account $account, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $account);
        $this->authorize('view', $importBatch);
        $this->ensureAccountBatch($account, $importBatch);

        return $this->renderShow(
            $request,
            $importBatch,
            [
                ['label' => 'Accounts', 'href' => route('accounts.index')],
                ['label' => $account->name, 'href' => route('accounts.show', $account)],
                ['label' => 'Imports', 'href' => route('accounts.imports.index', $account)],
                ['label' => 'Import batch'],
            ],
            route('accounts.imports.destroy', [$account, $importBatch]),
        );
    }

    public function destroyForAccount(
        Account $account,
        ImportBatch $importBatch,
        ImportBatchRevertService $reverter,
    ): RedirectResponse {
        $this->authorize('view', $account);
        $this->authorize('delete', $importBatch);
        $this->ensureAccountBatch($account, $importBatch);

        return $this->revert(
            $importBatch,
            $reverter,
            route('accounts.imports.index', $account),
        );
    }

    public function showForMerchant(Request $request, string $merchant, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);

        $vendor = $this->resolveVendor($merchant);
        $this->ensureMerchantBatch($vendor['normalized_name'], $importBatch);

        return $this->renderShow(
            $request,
            $importBatch,
            [
                ['label' => 'Orders', 'href' => route('orders.index')],
                ['label' => $vendor['name'], 'href' => route('orders.show', $merchant)],
                ['label' => 'Imports', 'href' => route('orders.imports.index', $merchant)],
                ['label' => 'Import batch'],
            ],
            route('orders.imports.destroy', [$merchant, $importBatch]),
        );
    }

    public function destroyForMerchant(
        string $merchant,
        ImportBatch $importBatch,
        ImportBatchRevertService $reverter,
    ): RedirectResponse {
        $this->authorize('delete', $importBatch);

        $vendor = $this->resolveVendor($merchant);
        $this->ensureMerchantBatch($vendor['normalized_name'], $importBatch);

        return $this->revert(
            $importBatch,
            $reverter,
            route('orders.imports.index', $merchant),
        );
    }

    public function showForVenmo(Request $request, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);
        $this->ensureVenmoBatch($importBatch);

        return $this->renderShow(
            $request,
            $importBatch,
            [
                ['label' => 'Accounts', 'href' => route('accounts.index')],
                ['label' => 'Venmo', 'href' => route('venmo.imports.index')],
                ['label' => 'Import batch'],
            ],
            route('venmo.imports.destroy', $importBatch),
        );
    }

    public function destroyForVenmo(
        ImportBatch $importBatch,
        ImportBatchRevertService $reverter,
    ): RedirectResponse {
        $this->authorize('delete', $importBatch);
        $this->ensureVenmoBatch($importBatch);

        return $this->revert(
            $importBatch,
            $reverter,
            route('venmo.imports.index'),
        );
    }

    /**
     * @param  list<array{label: string, href?: string}>  $breadcrumbs
     */
    protected function renderShow(
        Request $request,
        ImportBatch $importBatch,
        array $breadcrumbs,
        string $revertUrl,
    ): Response {
        $canRevert = $importBatch->canRevert();
        $page = max(1, $request->integer('page', 1));
        $perPage = 50;

        $dateRange = null;
        $transactions = null;
        $orders = null;
        $activities = null;
        $pagination = null;

        if ($importBatch->type === 'transactions') {
            $coverage = BankTransaction::query()
                ->where('import_batch_id', $importBatch->id)
                ->whereNotNull('posted_at')
                ->selectRaw('MIN(posted_at) as min_date, MAX(posted_at) as max_date, COUNT(*) as count')
                ->first();

            $minDate = $coverage?->min_date ? Carbon::parse($coverage->min_date)->toDateString() : null;
            $maxDate = $coverage?->max_date ? Carbon::parse($coverage->max_date)->toDateString() : null;
            $dateRange = [
                'min' => $minDate,
                'max' => $maxDate,
                'span_days' => $this->spanDays($minDate, $maxDate),
            ];

            $paginator = BankTransaction::query()
                ->with([
                    'merchant:id,name,normalized_name',
                    'category:id,name,kind',
                    'venmoActivities.cashedOutPayments',
                ])
                ->where('import_batch_id', $importBatch->id)
                ->orderByDesc('posted_at')
                ->orderByDesc('id')
                ->paginate(perPage: $perPage, page: $page);

            $transactions = $paginator->getCollection()->map(fn (BankTransaction $transaction): array => [
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
            ])->values()->all();

            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'first_item' => $paginator->firstItem(),
                'last_item' => $paginator->lastItem(),
            ];
        } elseif ($importBatch->type === 'orders') {
            $coverage = Order::query()
                ->where('import_batch_id', $importBatch->id)
                ->whereNotNull('ordered_at')
                ->selectRaw('MIN(ordered_at) as min_date, MAX(ordered_at) as max_date, COUNT(*) as count')
                ->first();

            $minDate = $coverage?->min_date ? Carbon::parse($coverage->min_date)->toDateString() : null;
            $maxDate = $coverage?->max_date ? Carbon::parse($coverage->max_date)->toDateString() : null;
            $dateRange = [
                'min' => $minDate,
                'max' => $maxDate,
                'span_days' => $this->spanDays($minDate, $maxDate),
            ];

            $paginator = Order::query()
                ->where('import_batch_id', $importBatch->id)
                ->with(['merchant:id,name,normalized_name', 'items:id,order_id'])
                ->orderByDesc('ordered_at')
                ->orderByDesc('id')
                ->paginate(perPage: $perPage, page: $page);

            $orders = $paginator->getCollection()->map(function (Order $order) use ($importBatch): array {
                $merchantNormalized = $order->merchant?->normalized_name ?? $importBatch->source;

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'ordered_at' => optional($order->ordered_at)?->toDateString(),
                    'delivered_at' => optional($order->delivered_at)?->toDateString(),
                    'total' => (float) $order->total,
                    'status' => $order->status,
                    'items_count' => $order->items->count(),
                    'payment_last_four' => $order->payment_last_four,
                    'merchant' => $order->merchant?->only(['id', 'name', 'normalized_name']),
                    'detail_url' => route('orders.detail', [$merchantNormalized, $order->id]),
                ];
            })->values()->all();

            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'first_item' => $paginator->firstItem(),
                'last_item' => $paginator->lastItem(),
            ];
        } elseif ($importBatch->type === 'activity') {
            $coverage = VenmoActivity::query()
                ->where('import_batch_id', $importBatch->id)
                ->whereNotNull('occurred_at')
                ->selectRaw('MIN(occurred_at) as min_date, MAX(occurred_at) as max_date, COUNT(*) as count')
                ->first();

            $minDate = $coverage?->min_date ? Carbon::parse($coverage->min_date)->toDateString() : null;
            $maxDate = $coverage?->max_date ? Carbon::parse($coverage->max_date)->toDateString() : null;
            $dateRange = [
                'min' => $minDate,
                'max' => $maxDate,
                'span_days' => $this->spanDays($minDate, $maxDate),
            ];

            $paginator = VenmoActivity::query()
                ->where('import_batch_id', $importBatch->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(perPage: $perPage, page: $page);

            $activities = $paginator->getCollection()->map(fn (VenmoActivity $activity): array => [
                'id' => $activity->id,
                'occurred_at' => optional($activity->occurred_at)?->toDateTimeString(),
                'type' => $activity->type,
                'status' => $activity->status,
                'note' => $activity->note,
                'from_name' => $activity->from_name,
                'to_name' => $activity->to_name,
                'amount' => (float) $activity->amount,
                'funding_source' => $activity->funding_source,
                'funding_last_four' => $activity->funding_last_four,
            ])->values()->all();

            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'first_item' => $paginator->firstItem(),
                'last_item' => $paginator->lastItem(),
            ];
        }

        return Inertia::render('Imports/Show', [
            'batch' => [
                ...$importBatch->only([
                    'id',
                    'source',
                    'type',
                    'original_filename',
                    'record_count',
                    'status',
                    'error_message',
                    'started_at',
                    'completed_at',
                    'created_at',
                    'metadata',
                ]),
                'date_range' => $dateRange,
            ],
            'breadcrumbs' => $breadcrumbs,
            'can_revert' => $canRevert,
            'revert_url' => $canRevert ? $revertUrl : null,
            'date_range' => $dateRange,
            'transactions' => $transactions,
            'orders' => $orders,
            'activities' => $activities,
            'pagination' => $pagination,
        ]);
    }

    protected function revert(
        ImportBatch $importBatch,
        ImportBatchRevertService $reverter,
        string $redirect,
    ): RedirectResponse {
        if (! $importBatch->canRevert()) {
            return redirect($redirect)
                ->with('error', 'This import cannot be reverted.');
        }

        $reverter->revert($importBatch);

        return redirect($redirect)
            ->with('success', "Import \"{$importBatch->original_filename}\" reverted.");
    }

    protected function ensureAccountBatch(Account $account, ImportBatch $importBatch): void
    {
        $accountId = $importBatch->metadata['account_id'] ?? null;

        if (
            ! in_array($importBatch->source, ['bank', 'tiller'], true)
            || $importBatch->type !== 'transactions'
            || (string) $accountId !== (string) $account->id
        ) {
            throw new NotFoundHttpException;
        }
    }

    protected function ensureMerchantBatch(string $merchant, ImportBatch $importBatch): void
    {
        if (
            $importBatch->source !== $merchant
            || $importBatch->type !== 'orders'
        ) {
            throw new NotFoundHttpException;
        }
    }

    protected function ensureVenmoBatch(ImportBatch $importBatch): void
    {
        if ($importBatch->source !== 'venmo' || $importBatch->type !== 'activity') {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @return array{normalized_name: string, name: string}
     */
    protected function resolveVendor(string $merchant): array
    {
        foreach (OrderBrowseService::BROWSABLE_MERCHANTS as $vendor) {
            if ($vendor['normalized_name'] === $merchant) {
                return $vendor;
            }
        }

        throw new NotFoundHttpException;
    }

    protected function spanDays(?string $min, ?string $max): ?int
    {
        if ($min === null || $max === null) {
            return null;
        }

        return (int) abs(Carbon::parse($min)->startOfDay()->diffInDays(Carbon::parse($max)->startOfDay(), false));
    }
}
