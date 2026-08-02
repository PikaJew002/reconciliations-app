<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreBankTransactionImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BankTransactionImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', ImportBatch::class);

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'institution_name', 'last_four']);

        return Inertia::render('Imports/BankTransactions/Create', [
            'accounts' => $accounts,
        ]);
    }

    public function store(StoreBankTransactionImportRequest $request): RedirectResponse
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
                'account_id' => $request->string('account_id')->toString(),
            ],
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('imports.show', $batch)
            ->with('success', 'Bank transaction import queued.');
    }
}
