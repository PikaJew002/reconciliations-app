<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreBankTransactionImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccountImportController extends Controller
{
    public function index(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);
        $this->authorize('create', ImportBatch::class);
        $this->assertCanImport($account);

        $accountId = (string) $account->id;

        $batches = ImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('source', ['bank', 'tiller'])
            ->where('type', 'transactions')
            ->where('metadata->account_id', $accountId)
            ->latest()
            ->get([
                'id',
                'source',
                'type',
                'original_filename',
                'record_count',
                'status',
                'error_message',
                'created_at',
                'completed_at',
            ]);

        $batchIds = $batches->pluck('id');
        $coverageByBatch = $batchIds->isEmpty()
            ? collect()
            : BankTransaction::query()
                ->whereIn('import_batch_id', $batchIds)
                ->whereNotNull('posted_at')
                ->selectRaw('import_batch_id, MIN(posted_at) as min_date, MAX(posted_at) as max_date')
                ->groupBy('import_batch_id')
                ->get()
                ->keyBy('import_batch_id');

        return Inertia::render('Accounts/Imports', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'account_type' => $account->account_type,
                'last_four' => $account->last_four,
            ],
            'batches' => $batches
                ->map(function (ImportBatch $batch) use ($coverageByBatch): array {
                    $coverage = $coverageByBatch->get($batch->id);
                    $dateRange = $coverage ? [
                        'min' => optional($coverage->min_date) ? Carbon::parse($coverage->min_date)->toDateString() : null,
                        'max' => optional($coverage->max_date) ? Carbon::parse($coverage->max_date)->toDateString() : null,
                    ] : null;

                    return $batch->historyPayload($dateRange);
                })
                ->values(),
        ]);
    }

    public function store(StoreBankTransactionImportRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('view', $account);
        $this->authorize('create', ImportBatch::class);
        $this->assertCanImport($account);

        $file = $request->file('file');
        $storagePath = 'imports/'.Str::uuid().'.csv';

        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'status' => 'pending',
            'metadata' => [
                'account_id' => (string) $account->id,
            ],
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('accounts.imports.show', [$account, $batch])
            ->with('success', 'Bank transaction import queued.');
    }

    protected function assertCanImport(Account $account): void
    {
        if ($account->isOffBook()) {
            abort(403, 'Off-book accounts cannot receive bank imports.');
        }
    }
}
