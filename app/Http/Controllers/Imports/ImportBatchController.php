<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Services\Orders\OrderBrowseService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImportBatchController extends Controller
{
    public function showForAccount(Account $account, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);
        $this->ensureAccountBatch($account, $importBatch);

        return $this->renderShow($importBatch, [
            ['label' => 'Accounts', 'href' => route('accounts.index')],
            ['label' => $account->name, 'href' => route('accounts.show', $account)],
            ['label' => 'Imports', 'href' => route('accounts.imports.index', $account)],
            ['label' => 'Import batch'],
        ]);
    }

    public function showForMerchant(string $merchant, ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);

        $vendor = $this->resolveVendor($merchant);
        $this->ensureMerchantBatch($vendor['normalized_name'], $importBatch);

        return $this->renderShow($importBatch, [
            ['label' => 'Orders', 'href' => route('orders.index')],
            ['label' => $vendor['name'], 'href' => route('orders.show', $merchant)],
            ['label' => 'Imports', 'href' => route('orders.imports.index', $merchant)],
            ['label' => 'Import batch'],
        ]);
    }

    /**
     * @param  list<array{label: string, href?: string}>  $breadcrumbs
     */
    protected function renderShow(ImportBatch $importBatch, array $breadcrumbs): Response
    {
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
        ]);
    }

    protected function ensureAccountBatch(Account $account, ImportBatch $importBatch): void
    {
        $accountId = $importBatch->metadata['account_id'] ?? null;

        if (
            $importBatch->source !== 'bank'
            || $importBatch->type !== 'transactions'
            || (string) $accountId !== (string) $account->id
        ) {
            throw new NotFoundHttpException();
        }
    }

    protected function ensureMerchantBatch(string $merchant, ImportBatch $importBatch): void
    {
        if (
            $importBatch->source !== $merchant
            || $importBatch->type !== 'orders'
        ) {
            throw new NotFoundHttpException();
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

        throw new NotFoundHttpException();
    }
}
