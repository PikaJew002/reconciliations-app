<?php

namespace App\Http\Controllers\Venmo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreVenmoActivityImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\VenmoActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        $batchIds = $batches->pluck('id');
        $coverageByBatch = $batchIds->isEmpty()
            ? collect()
            : VenmoActivity::query()
                ->whereIn('import_batch_id', $batchIds)
                ->whereNotNull('occurred_at')
                ->selectRaw('import_batch_id, MIN(occurred_at) as min_date, MAX(occurred_at) as max_date')
                ->groupBy('import_batch_id')
                ->get()
                ->keyBy('import_batch_id');

        return Inertia::render('Venmo/Imports', [
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
