<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StoreAmazonScrapeImportRequest;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AmazonImportController extends Controller
{
    public function store(StoreAmazonScrapeImportRequest $request): JsonResponse
    {
        $this->authorize('create', ImportBatch::class);

        $storagePath = 'imports/'.Str::uuid().'.json';
        $contents = $request->getContent();

        if ($contents === '') {
            $contents = json_encode($request->all(), JSON_THROW_ON_ERROR);
        }

        Storage::disk('local')->put($storagePath, $contents);

        $metadata = [
            'format' => 'scrape_json',
            ...$this->merchantMetadata($request->user()->id),
        ];

        $scrapedAt = $request->input('scrapedAt');

        if (is_string($scrapedAt) && $scrapedAt !== '') {
            $metadata['scraped_at'] = $scrapedAt;
        }

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'source' => 'amazon',
            'type' => 'orders',
            'original_filename' => 'amazon-scrape.json',
            'storage_path' => $storagePath,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        ProcessImportBatch::dispatch($batch);

        return response()->json([
            'success' => true,
            'message' => 'Amazon order import queued.',
            'batch_id' => $batch->id,
        ]);
    }

    /**
     * @return array{merchant_id?: int}
     */
    protected function merchantMetadata(int $userId): array
    {
        $merchantId = Merchant::query()
            ->where('user_id', $userId)
            ->where('normalized_name', 'amazon')
            ->value('id');

        if ($merchantId === null) {
            return [];
        }

        return ['merchant_id' => (int) $merchantId];
    }
}
