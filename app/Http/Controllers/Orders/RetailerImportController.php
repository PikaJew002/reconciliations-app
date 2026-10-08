<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreWalmartOrderImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RetailerImportController extends Controller
{
    public function index(Request $request, string $merchant): Response
    {
        $this->authorize('create', ImportBatch::class);

        $vendor = $this->resolveVendor($merchant);

        $batches = ImportBatch::query()
            ->where('user_id', $request->user()->id)
            ->where('source', $vendor['normalized_name'])
            ->where('type', 'orders')
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
            : Order::query()
                ->whereIn('import_batch_id', $batchIds)
                ->whereNotNull('ordered_at')
                ->selectRaw('import_batch_id, MIN(ordered_at) as min_date, MAX(ordered_at) as max_date')
                ->groupBy('import_batch_id')
                ->get()
                ->keyBy('import_batch_id');

        return Inertia::render('Orders/Imports', [
            'merchant' => $vendor,
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

    public function store(Request $request, string $merchant): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

        $vendor = $this->resolveVendor($merchant);

        if ($vendor['normalized_name'] !== 'walmart') {
            throw new NotFoundHttpException;
        }

        return $this->storeWalmart($request, $vendor);
    }

    /**
     * @param  array{normalized_name: string, name: string}  $vendor
     */
    protected function storeWalmart(Request $request, array $vendor): RedirectResponse
    {
        /** @var StoreWalmartOrderImportRequest $validated */
        $validated = app(StoreWalmartOrderImportRequest::class);

        $file = $validated->file('file');
        $storagePath = 'imports/'.Str::uuid().'.json';

        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'walmart',
            'type' => 'orders',
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'status' => 'pending',
            'metadata' => $this->merchantMetadata($request->user()->id, $vendor['normalized_name']),
        ]);

        ProcessImportBatch::dispatch($batch);

        return redirect()
            ->route('orders.imports.show', [$vendor['normalized_name'], $batch])
            ->with('success', 'Walmart order import queued.');
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

        throw new NotFoundHttpException;
    }

    /**
     * @return array{merchant_id?: int}
     */
    protected function merchantMetadata(int $userId, string $normalizedName): array
    {
        $merchantId = Merchant::query()
            ->where('user_id', $userId)
            ->where('normalized_name', $normalizedName)
            ->value('id');

        if ($merchantId === null) {
            return [];
        }

        return ['merchant_id' => (int) $merchantId];
    }
}
