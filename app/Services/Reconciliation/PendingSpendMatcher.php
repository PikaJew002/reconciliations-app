<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\TransactionTransferLink;
use App\Models\VenmoActivity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PendingSpendMatcher
{
    public function __construct(
        protected int $venmoActivityWindowDays = 5,
    ) {}

    /**
     * @return array{matched: int, ambiguous: int}
     */
    public function matchForUser(int $userId): array
    {
        /** @var Collection<int, PendingSpend> $pendings */
        $pendings = PendingSpend::query()
            ->where('user_id', $userId)
            ->whereIn('status', PendingSpend::unmatchedStatuses())
            ->with('merchant')
            ->orderBy('spent_at')
            ->orderBy('id')
            ->get();

        if ($pendings->isEmpty()) {
            return ['matched' => 0, 'ambiguous' => 0];
        }

        $claimedTransactionIds = $this->claimedBankTransactionIds($userId);
        $candidates = $this->candidateTransactions($userId, $claimedTransactionIds);

        /** @var array<int, list<int>> $candidateIdsByPending */
        $candidateIdsByPending = [];
        /** @var array<int, list<int>> $pendingIdsByTransaction */
        $pendingIdsByTransaction = [];

        foreach ($pendings as $pending) {
            if ($this->shouldSkipPending($pending)) {
                continue;
            }

            foreach ($candidates as $transaction) {
                if (! $this->pendingMatchesTransaction($pending, $transaction)) {
                    continue;
                }

                $candidateIdsByPending[$pending->id][] = $transaction->id;
                $pendingIdsByTransaction[$transaction->id][] = $pending->id;
            }
        }

        $matched = 0;
        $ambiguous = 0;
        $candidatesById = $candidates->keyBy('id');

        foreach ($pendings as $pending) {
            $transactionIds = $candidateIdsByPending[$pending->id] ?? [];

            if ($transactionIds === []) {
                continue;
            }

            $unique = $this->uniqueResolvableTransactionId(
                $transactionIds,
                $pendingIdsByTransaction,
            );

            if ($unique === null) {
                $this->markNeedsReview($pending, PendingSpend::REVIEW_AMBIGUOUS);
                $ambiguous++;

                continue;
            }

            $transaction = $candidatesById->get($unique);

            if ($transaction === null) {
                continue;
            }

            $this->resolve($pending, $transaction);
            $matched++;
        }

        return ['matched' => $matched, 'ambiguous' => $ambiguous];
    }

    /**
     * @return array{flagged: int}
     */
    public function promoteUnmatchedAfterImport(ImportBatch $batch): array
    {
        if ($batch->source !== 'bank' || $batch->type !== 'transactions') {
            return ['flagged' => 0];
        }

        $latestByAccount = $this->batchLatestDateByAccount($batch);

        if ($latestByAccount === []) {
            return ['flagged' => 0];
        }

        $pendings = PendingSpend::query()
            ->where('user_id', $batch->user_id)
            ->where('status', PendingSpend::STATUS_PENDING)
            ->whereIn('account_id', array_keys($latestByAccount))
            ->get();

        $flagged = 0;

        foreach ($pendings as $pending) {
            $latest = $latestByAccount[$pending->account_id] ?? null;

            if ($latest === null) {
                continue;
            }

            if ($pending->spentOn()->gt($latest)) {
                continue;
            }

            $this->markNeedsReview($pending, PendingSpend::REVIEW_NOT_FOUND);
            $flagged++;
        }

        return ['flagged' => $flagged];
    }

    public function link(PendingSpend $pendingSpend, BankTransaction $transaction): void
    {
        if (! $pendingSpend->isUnmatched()) {
            throw new InvalidArgumentException('Only unmatched pending spend can be linked.');
        }

        if ((int) $transaction->user_id !== (int) $pendingSpend->user_id) {
            throw new InvalidArgumentException('That transaction does not belong to the same user.');
        }

        if (! $this->amountsMatch($pendingSpend, $transaction)) {
            throw new InvalidArgumentException('Pending spend can only be linked to an exact-amount debit.');
        }

        if ($this->shouldSkipTransaction($transaction, $this->claimedBankTransactionIds((int) $pendingSpend->user_id))) {
            throw new InvalidArgumentException('That transaction is not available to link.');
        }

        $this->resolve($pendingSpend, $transaction);
    }

    /**
     * @param  list<int>  $transactionIds
     * @param  array<int, list<int>>  $pendingIdsByTransaction
     */
    protected function uniqueResolvableTransactionId(
        array $transactionIds,
        array $pendingIdsByTransaction,
    ): ?int {
        $transactionIds = array_values(array_unique($transactionIds));

        if (count($transactionIds) !== 1) {
            return null;
        }

        $transactionId = $transactionIds[0];
        $pendingIds = array_values(array_unique($pendingIdsByTransaction[$transactionId] ?? []));

        if (count($pendingIds) !== 1) {
            return null;
        }

        return $transactionId;
    }

    protected function resolve(PendingSpend $pending, BankTransaction $transaction): void
    {
        $txUpdates = [];

        if (! $pending->isVenmo() && $transaction->merchant_id === null && $pending->merchant_id !== null) {
            $txUpdates['merchant_id'] = $pending->merchant_id;
        }

        if ($transaction->classification === null) {
            $txUpdates['classification'] = $pending->classification;
            $txUpdates['classification_source'] = BankTransaction::CLASSIFICATION_SOURCE_LEARNED;
            $txUpdates['classification_confidence'] = 100;
        }

        if ($transaction->category_id === null && $pending->category_id !== null) {
            $txUpdates['category_id'] = $pending->category_id;
        }

        if ($txUpdates !== []) {
            $txUpdates['status'] = 'ignored';
            $transaction->update($txUpdates);
        } elseif ($transaction->status === 'unmatched') {
            $transaction->update(['status' => 'ignored']);
        }

        $pending->update([
            'bank_transaction_id' => $transaction->id,
            'venmo_activity_id' => $pending->isVenmo()
                ? ($this->matchingVenmoActivityId($pending, $transaction) ?? $pending->venmo_activity_id)
                : $pending->venmo_activity_id,
            'status' => PendingSpend::STATUS_RESOLVED,
            'review_reason' => null,
        ]);
    }

    protected function markNeedsReview(PendingSpend $pending, string $reason): void
    {
        if ($pending->status === PendingSpend::STATUS_NEEDS_REVIEW
            && $pending->review_reason === PendingSpend::REVIEW_AMBIGUOUS
            && $reason === PendingSpend::REVIEW_NOT_FOUND
        ) {
            return;
        }

        $pending->update([
            'status' => PendingSpend::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
        ]);
    }

    protected function shouldSkipPending(PendingSpend $pending): bool
    {
        return $pending->merchant?->supports_order_import === true;
    }

    protected function pendingMatchesTransaction(
        PendingSpend $pending,
        BankTransaction $transaction,
    ): bool {
        if ((float) $transaction->amount >= 0) {
            return false;
        }

        if (! $this->amountsMatch($pending, $transaction)) {
            return false;
        }

        if (! $this->accountOrCardMatches($pending, $transaction)) {
            return false;
        }

        if (! $this->dateInWindow($pending, $transaction)) {
            return false;
        }

        if ($pending->isVenmo()) {
            return $this->looksLikeVenmo($transaction);
        }

        if ($transaction->merchant_id !== null && $pending->merchant_id !== null) {
            return (int) $transaction->merchant_id === (int) $pending->merchant_id;
        }

        return true;
    }

    protected function amountsMatch(PendingSpend $pending, BankTransaction $transaction): bool
    {
        return round((float) $pending->amount, 2) === round(abs((float) $transaction->amount), 2);
    }

    protected function accountOrCardMatches(PendingSpend $pending, BankTransaction $transaction): bool
    {
        if ((string) $pending->account_id === (string) $transaction->account_id) {
            return true;
        }

        return $pending->card_last_four !== null
            && $transaction->card_last_four !== null
            && $pending->card_last_four === $transaction->card_last_four;
    }

    protected function dateInWindow(PendingSpend $pending, BankTransaction $transaction): bool
    {
        $transactionDate = $this->transactionMatchDate($transaction);

        return $transactionDate->gte($pending->windowStart())
            && $transactionDate->lte($pending->windowEnd());
    }

    protected function transactionMatchDate(BankTransaction $transaction): CarbonInterface
    {
        $date = $transaction->transaction_date ?? $transaction->posted_at;

        return Carbon::parse($date)->startOfDay();
    }

    protected function looksLikeVenmo(BankTransaction $transaction): bool
    {
        $description = strtolower((string) ($transaction->normalized_description ?: $transaction->description));

        return str_contains($description, 'venmo');
    }

    /**
     * @param  list<int>  $claimedTransactionIds
     * @return Collection<int, BankTransaction>
     */
    protected function candidateTransactions(int $userId, array $claimedTransactionIds): Collection
    {
        $activeTransferStatuses = [
            TransactionTransferLink::STATUS_SUGGESTED,
            TransactionTransferLink::STATUS_CONFIRMED,
        ];

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', '<', 0)
            ->where(function ($query) {
                $query->whereNull('classification')
                    ->orWhereIn('classification', [
                        BankTransaction::CLASSIFICATION_BILL,
                        BankTransaction::CLASSIFICATION_EXPENSE,
                    ]);
            })
            ->whereDoesntHave('allocations')
            ->whereDoesntHave('reimbursementGroupLeg')
            ->whereDoesntHave(
                'debitTransferLink',
                fn ($linkQuery) => $linkQuery->whereIn('status', $activeTransferStatuses),
            )
            ->whereDoesntHave(
                'creditTransferLink',
                fn ($linkQuery) => $linkQuery->whereIn('status', $activeTransferStatuses),
            )
            ->when(
                $claimedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $claimedTransactionIds),
            )
            ->orderBy('id')
            ->get();
    }

    protected function shouldSkipTransaction(BankTransaction $transaction, array $claimedTransactionIds): bool
    {
        if (in_array((int) $transaction->id, $claimedTransactionIds, true)) {
            return true;
        }

        if ((float) $transaction->amount >= 0) {
            return true;
        }

        if ($transaction->allocations()->exists()) {
            return true;
        }

        if ($transaction->isInReimbursementGroup()) {
            return true;
        }

        $classification = $transaction->classification;

        if ($classification !== null && ! in_array($classification, [
            BankTransaction::CLASSIFICATION_BILL,
            BankTransaction::CLASSIFICATION_EXPENSE,
        ], true)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    protected function claimedBankTransactionIds(int $userId): array
    {
        $pendingIds = PendingSpend::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $plannedIds = PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->pluck('bank_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$pendingIds, ...$plannedIds]));
    }

    /**
     * @return array<string, CarbonInterface>
     */
    protected function batchLatestDateByAccount(ImportBatch $batch): array
    {
        $latest = [];

        $transactions = BankTransaction::query()
            ->where('import_batch_id', $batch->id)
            ->get(['account_id', 'posted_at', 'transaction_date']);

        foreach ($transactions as $transaction) {
            $date = $this->transactionMatchDate($transaction);
            $accountId = (string) $transaction->account_id;
            $current = $latest[$accountId] ?? null;

            if ($current === null || $date->gt($current)) {
                $latest[$accountId] = $date;
            }
        }

        return $latest;
    }

    protected function matchingVenmoActivityId(
        PendingSpend $pending,
        BankTransaction $transaction,
    ): ?int {
        $from = Carbon::parse($pending->spent_at)->subDays($this->venmoActivityWindowDays);
        $to = Carbon::parse($pending->spent_at)->addDays($this->venmoActivityWindowDays);
        $amount = round((float) $pending->amount, 2);

        $activities = VenmoActivity::query()
            ->where('user_id', $pending->user_id)
            ->whereDoesntHave('pendingSpend')
            ->where(function ($query) use ($transaction) {
                $query->whereNull('bank_transaction_id')
                    ->orWhere('bank_transaction_id', $transaction->id);
            })
            ->whereBetween('occurred_at', [$from, $to])
            ->get()
            ->filter(fn (VenmoActivity $activity): bool => round(abs((float) $activity->amount), 2) === $amount)
            ->values();

        if ($activities->count() !== 1) {
            return null;
        }

        return (int) $activities->first()->id;
    }
}
