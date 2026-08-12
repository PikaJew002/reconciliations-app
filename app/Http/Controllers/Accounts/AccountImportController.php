<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreBankTransactionImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AccountImportController extends Controller
{
    public function index(Request $request, Account $account): Response
    {
        $this->authorize('create', ImportBatch::class);

        $accountId = (string) $account->id;

        $batches = ImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'bank')
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

        return Inertia::render('Accounts/Imports', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'account_type' => $account->account_type,
                'last_four' => $account->last_four,
            ],
            'batches' => $batches,
        ]);
    }

    public function store(StoreBankTransactionImportRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

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
            ->route('imports.show', $batch)
            ->with('success', 'Bank transaction import queued.');
    }
}
