<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\TillerConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TillerSheetCallback
{
    public function send(int $userId, string $syncId): void
    {
        $connection = TillerConnection::query()->where('user_id', $userId)->first();

        if (! $connection instanceof TillerConnection || $connection->callback_url === '' || $connection->webhook_secret === '') {
            return;
        }

        $transactionIds = ImportBatch::query()
            ->where('user_id', $userId)
            ->where('metadata->tiller_sync_id', $syncId)
            ->where('status', 'completed')
            ->get()
            ->flatMap(function (ImportBatch $batch): array {
                $ids = $batch->metadata['acknowledged_transaction_ids'] ?? [];

                return is_array($ids) ? $ids : [];
            })
            ->map(fn (mixed $id): string => (string) $id)
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($transactionIds === []) {
            return;
        }

        $separator = str_contains($connection->callback_url, '?') ? '&' : '?';
        $url = $connection->callback_url.$separator.'key='.urlencode($connection->webhook_secret);

        $response = Http::withOptions([
            'allow_redirects' => [
                'strict' => true,
            ],
        ])->withBody(
            json_encode(array_values($transactionIds), JSON_THROW_ON_ERROR),
            'application/json',
        )->post($url);

        if ($response->successful() && $response->json('ok') === true) {
            return;
        }

        Log::warning('Tiller sheet callback failed.', [
            'user_id' => $userId,
            'tiller_sync_id' => $syncId,
            'status' => $response->status(),
        ]);
    }
}
