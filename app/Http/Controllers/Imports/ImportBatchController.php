<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportBatchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ImportBatch::class);

        $batches = ImportBatch::query()
            ->where('user_id', $request->user()->id)
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

        return Inertia::render('Imports/Index', [
            'batches' => $batches,
        ]);
    }

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
        ]);
    }
}
