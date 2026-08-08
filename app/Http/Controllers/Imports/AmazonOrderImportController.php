<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreAmazonOrderImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AmazonOrderImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', ImportBatch::class);

        return Inertia::render('Imports/AmazonOrders/Create');
    }

    public function store(StoreAmazonOrderImportRequest $request): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

        if ($request->filled('merchant_id')) {
            $ownsMerchant = Merchant::query()
                ->where('user_id', $request->user()->id)
                ->whereKey($request->integer('merchant_id'))
                ->exists();

            abort_unless($ownsMerchant, 403);
        }

        $summaryFile = $request->file('summary_file');
        $itemsFile = $request->file('items_file');
        $directory = 'imports/'.Str::uuid();
        $summaryPath = $directory.'/summary.csv';
        $itemsPath = $directory.'/items.csv';

        Storage::disk('local')->put($summaryPath, file_get_contents($summaryFile->getRealPath()));
        Storage::disk('local')->put($itemsPath, file_get_contents($itemsFile->getRealPath()));

        $metadata = [
            'items_path' => $itemsPath,
            'summary_filename' => $summaryFile->getClientOriginalName(),
            'items_filename' => $itemsFile->getClientOriginalName(),
        ];

        if ($request->filled('merchant_id')) {
            $metadata['merchant_id'] = $request->integer('merchant_id');
        }

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => $summaryFile->getClientOriginalName().' + '.$itemsFile->getClientOriginalName(),
            'storage_path' => $summaryPath,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('imports.show', $batch)
            ->with('success', 'Amazon order import queued.');
    }
}
