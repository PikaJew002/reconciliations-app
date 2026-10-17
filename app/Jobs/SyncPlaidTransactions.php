<?php

namespace App\Jobs;

use App\Models\PlaidItem;
use App\Services\Plaid\PlaidRequestException;
use App\Services\Plaid\PlaidTransactionSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPlaidTransactions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 180;

    public function __construct(public string $plaidItemId) {}

    public function uniqueId(): string
    {
        return $this->plaidItemId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(PlaidTransactionSync $sync): void
    {
        $item = PlaidItem::query()->find($this->plaidItemId);

        if (! $item instanceof PlaidItem) {
            return;
        }

        try {
            $sync->sync($item);
        } catch (PlaidRequestException $exception) {
            Log::warning('Plaid sync failed', [
                'request_id' => $exception->requestId,
                'error_code' => $exception->errorCode,
                'error_type' => $exception->errorType,
            ]);

            if (! $exception->shouldRetry() || $exception->errorCode === 'ITEM_LOGIN_REQUIRED') {
                $item->update([
                    'error_code' => $exception->errorCode,
                ]);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception instanceof PlaidRequestException) {
            return;
        }

        PlaidItem::query()
            ->whereKey($this->plaidItemId)
            ->update([
                'error_code' => $exception->errorCode,
            ]);
    }
}
