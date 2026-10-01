<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Services\Imports\ImportBatchRevertService;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImportBatchController extends Controller
{
    public function showForAccount(Account $account, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $account);
        $this->authorize('view', $importBatch);
        $this->ensureAccountBatch($account, $importBatch);

        return $this->renderShow(
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

    public function showForMerchant(string $merchant, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);

        $vendor = $this->resolveVendor($merchant);
        $this->ensureMerchantBatch($vendor['normalized_name'], $importBatch);

        return $this->renderShow(
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

    public function showForVenmo(ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);
        $this->ensureVenmoBatch($importBatch);

        return $this->renderShow(
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
        ImportBatch $importBatch,
        array $breadcrumbs,
        string $revertUrl,
    ): Response {
        $canRevert = $importBatch->canRevert();

        return Inertia::render('Imports/Show', [
            'batch' => $importBatch->only([
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
            'breadcrumbs' => $breadcrumbs,
            'can_revert' => $canRevert,
            'revert_url' => $canRevert ? $revertUrl : null,
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
}
