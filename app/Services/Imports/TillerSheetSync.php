<?php

namespace App\Services\Imports;

use App\Models\TillerConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TillerSheetSync
{
    public function pull(TillerConnection $connection): int
    {
        try {
            $response = Http::timeout(60)->get($this->url($connection));
        } catch (ConnectionException) {
            throw new RuntimeException('Tiller sheet did not respond.');
        }

        $sent = $response->json('sent');

        if ($response->successful() && $response->json('ok') === true && is_int($sent)) {
            return $sent;
        }

        Log::warning('Tiller sheet sync failed.', [
            'user_id' => $connection->user_id,
            'status' => $response->status(),
        ]);

        $error = $response->json('error');

        throw new RuntimeException(
            is_string($error) && $error !== ''
                ? $error
                : 'Tiller sheet sync failed.',
        );
    }

    private function url(TillerConnection $connection): string
    {
        $separator = str_contains($connection->callback_url, '?') ? '&' : '?';

        return $connection->callback_url.$separator.'key='.urlencode($connection->webhook_secret);
    }
}
