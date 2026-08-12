<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreAmazonOrderImportRequest;
use App\Http\Requests\Imports\StoreWalmartOrderImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Services\Orders\OrderBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('Orders/Imports', [
            'merchant' => $vendor,
            'batches' => $batches,
        ]);
    }

    public function store(Request $request, string $merchant): RedirectResponse
    {
        $this->authorize('create', ImportBatch::class);

        $vendor = $this->resolveVendor($merchant);

        return match ($vendor['normalized_name']) {
            'walmart' => $this->storeWalmart($request, $vendor),
            'amazon' => $this->storeAmazon($request, $vendor),
            default => throw new NotFoundHttpException(),
        };
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
     * @param  array{normalized_name: string, name: string}  $vendor
     */
    protected function storeAmazon(Request $request, array $vendor): RedirectResponse
    {
        /** @var StoreAmazonOrderImportRequest $validated */
        $validated = app(StoreAmazonOrderImportRequest::class);

        $summaryFile = $validated->file('summary_file');
        $itemsFile = $validated->file('items_file');
        $directory = 'imports/'.Str::uuid();
        $summaryPath = $directory.'/summary.csv';
        $itemsPath = $directory.'/items.csv';

        Storage::disk('local')->put($summaryPath, file_get_contents($summaryFile->getRealPath()));
        Storage::disk('local')->put($itemsPath, file_get_contents($itemsFile->getRealPath()));

        $metadata = [
            'items_path' => $itemsPath,
            'summary_filename' => $summaryFile->getClientOriginalName(),
            'items_filename' => $itemsFile->getClientOriginalName(),
            ...$this->merchantMetadata($request->user()->id, $vendor['normalized_name']),
        ];

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
            ->route('orders.imports.show', [$vendor['normalized_name'], $batch])
            ->with('success', 'Amazon order import queued.');
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

        throw new NotFoundHttpException();
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
