<?php

namespace App\Services\Imports;

use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;
use App\Models\TransactionAllocation;
use App\Models\TransactionTransferLink;
use App\Models\VenmoActivity;
use App\Services\Orders\OrderRemovalService;
use App\Services\Reconciliation\ReimbursementGroupService;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportBatchRevertService
{
    public function __construct(
        protected TransferPairingService $transferPairing,
        protected ReimbursementGroupService $reimbursementGroups,
        protected OrderRemovalService $orderRemoval,
    ) {}

    public function revert(ImportBatch $batch): void
    {
        if (! $batch->canRevert()) {
            throw new RuntimeException('This import cannot be reverted.');
        }

        DB::transaction(function () use ($batch): void {
            if (in_array($batch->source, ['bank', 'tiller'], true) && $batch->type === 'transactions') {
                $this->revertBankBatch($batch);
            } elseif ($batch->source === 'venmo' && $batch->type === 'activity') {
                $this->revertVenmoBatch($batch);
            } elseif ($batch->type === 'orders') {
                $this->revertOrderBatch($batch);
            }

            $batch->markReverted();
        });

        $this->deleteStoredFile($batch);
    }

    protected function revertBankBatch(ImportBatch $batch): void
    {
        $transactionIds = $batch->bankTransactions()->pluck('id');

        if ($transactionIds->isEmpty()) {
            return;
        }

        $orderIds = $this->orderIdsAllocatedTo($transactionIds);

        $this->unpairTransferLinks($batch->user_id, $transactionIds);
        $this->destroyReimbursementGroups($transactionIds);

        PlannedOccurrence::query()
            ->whereIn('bank_transaction_id', $transactionIds)
            ->update([
                'bank_transaction_id' => null,
                'status' => PlannedOccurrence::STATUS_PLANNED,
            ]);

        PendingSpend::query()
            ->whereIn('bank_transaction_id', $transactionIds)
            ->update([
                'bank_transaction_id' => null,
                'status' => PendingSpend::STATUS_PENDING,
                'review_reason' => null,
            ]);

        VenmoActivity::query()
            ->whereIn('bank_transaction_id', $transactionIds)
            ->update([
                'bank_transaction_id' => null,
                'match_status' => VenmoActivity::STATUS_UNMATCHED,
            ]);

        BankTransaction::query()->whereIn('id', $transactionIds)->delete();

        $this->reopenOrders($orderIds);
    }

    protected function revertVenmoBatch(ImportBatch $batch): void
    {
        $activityIds = $batch->venmoActivities()->pluck('id');

        if ($activityIds->isEmpty()) {
            return;
        }

        PendingSpend::query()
            ->whereIn('venmo_activity_id', $activityIds)
            ->update([
                'venmo_activity_id' => null,
                'bank_transaction_id' => null,
                'status' => PendingSpend::STATUS_PENDING,
                'review_reason' => null,
            ]);

        VenmoActivity::query()->whereIn('id', $activityIds)->delete();
    }

    protected function revertOrderBatch(ImportBatch $batch): void
    {
        $batch->orders()
            ->get()
            ->each(fn (Order $order) => $this->orderRemoval->remove($order));
    }

    /**
     * @param  Collection<int, int|string>  $transactionIds
     * @return Collection<int, int|string>
     */
    protected function orderIdsAllocatedTo(Collection $transactionIds): Collection
    {
        return TransactionAllocation::query()
            ->whereIn('bank_transaction_id', $transactionIds)
            ->join('order_components', 'order_components.id', '=', 'transaction_allocations.order_component_id')
            ->pluck('order_components.order_id')
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int|string>  $transactionIds
     */
    protected function unpairTransferLinks(int $userId, Collection $transactionIds): void
    {
        TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                TransactionTransferLink::STATUS_SUGGESTED,
                TransactionTransferLink::STATUS_CONFIRMED,
            ])
            ->where(function ($query) use ($transactionIds): void {
                $query
                    ->whereIn('debit_transaction_id', $transactionIds)
                    ->orWhereIn('credit_transaction_id', $transactionIds);
            })
            ->get()
            ->each(fn (TransactionTransferLink $link) => $this->transferPairing->unpairLink($link));
    }

    /**
     * @param  Collection<int, int|string>  $transactionIds
     */
    protected function destroyReimbursementGroups(Collection $transactionIds): void
    {
        $groupIds = ReimbursementGroupTransaction::query()
            ->whereIn('bank_transaction_id', $transactionIds)
            ->pluck('reimbursement_group_id')
            ->unique()
            ->values();

        if ($groupIds->isEmpty()) {
            return;
        }

        ReimbursementGroup::query()
            ->whereIn('id', $groupIds)
            ->get()
            ->each(fn (ReimbursementGroup $group) => $this->reimbursementGroups->destroy($group));
    }

    /**
     * @param  Collection<int, int|string>  $orderIds
     */
    protected function reopenOrders(Collection $orderIds): void
    {
        if ($orderIds->isEmpty()) {
            return;
        }

        Order::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->each(function (Order $order): void {
                $order->unsetRelation('components');

                if ($order->status === 'reconciled' && ! $order->is_fully_allocated) {
                    $order->update([
                        'status' => 'imported',
                    ]);
                }
            });
    }

    protected function deleteStoredFile(ImportBatch $batch): void
    {
        $path = $batch->storage_path;

        if ($path === null || $path === '') {
            return;
        }

        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
