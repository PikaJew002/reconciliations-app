<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreWalmartOrderImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WalmartOrderImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', ImportBatch::class);

        return Inertia::render('Imports/WalmartOrders/Create');
    }

    public function store(StoreWalmartOrderImportRequest $request): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

        if ($request->filled('merchant_id')) {
            $ownsMerchant = Merchant::query()
                ->where('user_id', $request->user()->id)
                ->whereKey($request->integer('merchant_id'))
                ->exists();

            abort_unless($ownsMerchant, 403);
        }

        $file = $request->file('file');
        $storagePath = 'imports/'.Str::uuid().'.json';

        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $metadata = [];

        if ($request->filled('merchant_id')) {
            $metadata['merchant_id'] = $request->integer('merchant_id');
        }

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'walmart',
            'type' => 'orders',
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('imports.show', $batch)
            ->with('success', 'Walmart order import queued.');
    }
}
