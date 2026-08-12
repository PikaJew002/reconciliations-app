<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use Inertia\Inertia;
use Inertia\Response;

class ImportBatchController extends Controller
{
    public function show(ImportBatch $importBatch): Response
    {
        $this->authorize('view', $importBatch);

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
            ...$this->backNavigation($importBatch),
        ]);
    }

    /**
     * @return array{backHref: string, backLabel: string}
     */
    protected function backNavigation(ImportBatch $importBatch): array
    {
        if ($importBatch->source === 'bank' && $importBatch->type === 'transactions') {
            $accountId = $importBatch->metadata['account_id'] ?? null;

            if ($accountId !== null && $accountId !== '') {
                return [
                    'backHref' => route('accounts.imports.index', $accountId),
                    'backLabel' => 'Account imports',
                ];
            }

            return [
                'backHref' => route('accounts.index'),
                'backLabel' => 'Accounts',
            ];
        }

        if (in_array($importBatch->source, ['walmart', 'amazon'], true) && $importBatch->type === 'orders') {
            return [
                'backHref' => route('orders.imports.index', $importBatch->source),
                'backLabel' => ucfirst($importBatch->source).' imports',
            ];
        }

        return [
            'backHref' => route('dashboard'),
            'backLabel' => 'Home',
        ];
    }
}
