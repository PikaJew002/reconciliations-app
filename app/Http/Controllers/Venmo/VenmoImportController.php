<?php

namespace App\Http\Controllers\Venmo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreVenmoActivityImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class VenmoImportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('create', ImportBatch::class);

        $batches = ImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'venmo')
            ->where('type', 'activity')
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

        return Inertia::render('Venmo/Imports', [
            'batches' => $batches,
        ]);
    }

    public function store(StoreVenmoActivityImportRequest $request): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

        $file = $request->file('file');
        $storagePath = 'imports/'.Str::uuid().'.csv';

        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'venmo',
            'type' => 'activity',
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'status' => 'pending',
            'metadata' => [],
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('venmo.imports.show', $batch)
            ->with('success', 'Venmo statement import queued.');
    }
}
