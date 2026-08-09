<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\TransactionTransferLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferPairingService
{
    /**
     * @var list<string>
     */
    protected array $transferDescriptionPatterns = [
        'transfer',
        'xfer',
        'xfr',
    ];

    public function __construct(
        protected int $dateWindowDays = 2,
    ) {}

    /**
     * @return array{confirmed: int, suggested: int}
     */
    public function pairForUser(int $userId): array
    {
        $confirmed = 0;
        $suggested = 0;

        $transactions = $this->eligibleTransactions($userId);

        if ($transactions->isEmpty()) {
            return ['confirmed' => 0, 'suggested' => 0];
        }

        $debits = $transactions->filter(fn (BankTransaction $txn): bool => (float) $txn->amount < 0)->values();
        $credits = $transactions->filter(fn (BankTransaction $txn): bool => (float) $txn->amount > 0)->values();

        $usedCreditIds = [];

        foreach ($debits as $debit) {
            $candidates = $credits
                ->filter(function (BankTransaction $credit) use ($debit, $usedCreditIds): bool {
                    if (isset($usedCreditIds[$credit->id])) {
                        return false;
                    }

                    if ($credit->account_id === $debit->account_id) {
                        return false;
                    }

                    if (! $this->amountsEqual(abs((float) $debit->amount), (float) $credit->amount)) {
                        return false;
                    }

                    return $this->withinDateWindow($debit, $credit);
                })
                ->values();

            $credit = $this->resolveUniqueCandidate($debit, $candidates);

            if ($credit === null) {
                continue;
            }

            $usedCreditIds[$credit->id] = true;

            $confidence = $this->confidenceForPair($debit, $credit);

            if ($this->shouldAutoConfirm($debit, $credit)) {
                $this->confirmPair($userId, $debit, $credit, $confidence, auto: true);
                $confirmed++;

                continue;
            }

            $this->suggestPair($userId, $debit, $credit, $confidence);
            $suggested++;
        }

        return [
            'confirmed' => $confirmed,
            'suggested' => $suggested,
        ];
    }

    public function confirmLink(TransactionTransferLink $link): void
    {
        if ($link->status === TransactionTransferLink::STATUS_CONFIRMED) {
            return;
        }

        $link->loadMissing(['debitTransaction', 'creditTransaction']);

        DB::transaction(function () use ($link): void {
            $this->applyConfirmedClassification(
                $link->debitTransaction,
                $link->creditTransaction,
                $link->transfer_group_id,
                (float) ($link->match_confidence ?? 100),
            );

            $link->update([
                'status' => TransactionTransferLink::STATUS_CONFIRMED,
            ]);
        });
    }

    public function rejectLink(TransactionTransferLink $link): void
    {
        if ($link->status === TransactionTransferLink::STATUS_REJECTED) {
            return;
        }

        $link->update([
            'status' => TransactionTransferLink::STATUS_REJECTED,
        ]);
    }

    public function unpairLink(TransactionTransferLink $link): void
    {
        $link->loadMissing(['debitTransaction', 'creditTransaction']);

        DB::transaction(function () use ($link): void {
            foreach ([$link->debitTransaction, $link->creditTransaction] as $transaction) {
                if (! $transaction) {
                    continue;
                }

                $transaction->update([
                    'classification' => null,
                    'classification_source' => null,
                    'classification_confidence' => null,
                    'transfer_group_id' => null,
                    'status' => 'unmatched',
                ]);
            }

            // Delete so unique debit/credit constraints do not block correct re-pairing.
            $link->delete();
        });
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function eligibleTransactions(int $userId): Collection
    {
        $linkedIds = TransactionTransferLink::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                TransactionTransferLink::STATUS_SUGGESTED,
                TransactionTransferLink::STATUS_CONFIRMED,
            ])
            ->get(['debit_transaction_id', 'credit_transaction_id'])
            ->flatMap(fn (TransactionTransferLink $link): array => [
                $link->debit_transaction_id,
                $link->credit_transaction_id,
            ])
            ->unique()
            ->all();

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->whereNull('classification')
            ->whereNull('transfer_group_id')
            ->when($linkedIds !== [], fn ($query) => $query->whereNotIn('id', $linkedIds))
            ->whereHas('account', function ($query): void {
                $query->whereIn('account_type', [Account::CHECKING, Account::SAVINGS]);
            })
            ->with('account:id,account_type,last_four')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();
    }

    protected function suggestPair(
        int $userId,
        BankTransaction $debit,
        BankTransaction $credit,
        float $confidence,
    ): void {
        TransactionTransferLink::query()->create([
            'user_id' => $userId,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => $confidence,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => [
                'source' => 'auto',
            ],
        ]);
    }

    protected function confirmPair(
        int $userId,
        BankTransaction $debit,
        BankTransaction $credit,
        float $confidence,
        bool $auto = false,
    ): void {
        DB::transaction(function () use ($userId, $debit, $credit, $confidence, $auto): void {
            $groupId = (string) Str::uuid();

            TransactionTransferLink::query()->create([
                'user_id' => $userId,
                'debit_transaction_id' => $debit->id,
                'credit_transaction_id' => $credit->id,
                'transfer_group_id' => $groupId,
                'match_confidence' => $confidence,
                'status' => TransactionTransferLink::STATUS_CONFIRMED,
                'metadata' => [
                    'source' => $auto ? 'auto' : 'manual',
                ],
            ]);

            $this->applyConfirmedClassification($debit, $credit, $groupId, $confidence);
        });
    }

    protected function applyConfirmedClassification(
        BankTransaction $debit,
        BankTransaction $credit,
        string $groupId,
        float $confidence,
    ): void {
        foreach ([$debit, $credit] as $transaction) {
            $transaction->update([
                'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
                'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_PAIRED,
                'classification_confidence' => $confidence,
                'transfer_group_id' => $groupId,
                'status' => 'ignored',
            ]);
        }
    }

    /**
     * @param  Collection<int, BankTransaction>  $candidates
     */
    protected function resolveUniqueCandidate(
        BankTransaction $debit,
        Collection $candidates,
    ): ?BankTransaction {
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() < 2) {
            return null;
        }

        $debitMemo = $this->normalizedDescription($debit);

        if ($debitMemo === '') {
            return null;
        }

        $memoMatches = $candidates
            ->filter(
                fn (BankTransaction $credit): bool => $this->normalizedDescription($credit) === $debitMemo,
            )
            ->values();

        if ($memoMatches->count() !== 1) {
            return null;
        }

        return $memoMatches->first();
    }

    protected function shouldAutoConfirm(BankTransaction $debit, BankTransaction $credit): bool
    {
        return $this->hasIdenticalMemo($debit, $credit)
            && $this->postedAt($debit)->equalTo($this->postedAt($credit));
    }

    protected function hasIdenticalMemo(BankTransaction $debit, BankTransaction $credit): bool
    {
        $debitMemo = $this->normalizedDescription($debit);

        return $debitMemo !== ''
            && $debitMemo === $this->normalizedDescription($credit);
    }

    protected function confidenceForPair(BankTransaction $debit, BankTransaction $credit): float
    {
        $transferLike = $this->looksLikeTransfer($debit) || $this->looksLikeTransfer($credit);
        $identicalMemo = $this->hasIdenticalMemo($debit, $credit);
        $samePostedDate = $this->postedAt($debit)->equalTo($this->postedAt($credit));

        if ($identicalMemo && $samePostedDate) {
            return 98.0;
        }

        if ($identicalMemo) {
            return 85.0;
        }

        if ($samePostedDate && $transferLike) {
            return 80.0;
        }

        if ($samePostedDate) {
            return 70.0;
        }

        if ($transferLike) {
            return 65.0;
        }

        return 60.0;
    }

    protected function looksLikeTransfer(BankTransaction $transaction): bool
    {
        $description = $this->normalizedDescription($transaction);

        foreach ($this->transferDescriptionPatterns as $pattern) {
            if (str_contains($description, $pattern)) {
                return true;
            }
        }

        $lastFour = $transaction->account?->last_four;

        if ($lastFour && str_contains($description, $lastFour)) {
            return true;
        }

        return false;
    }

    protected function withinDateWindow(BankTransaction $left, BankTransaction $right): bool
    {
        return abs($this->postedAt($left)->diffInDays($this->postedAt($right), false)) <= $this->dateWindowDays;
    }

    protected function postedAt(BankTransaction $transaction): Carbon
    {
        return Carbon::parse($transaction->posted_at)->startOfDay();
    }

    protected function normalizedDescription(BankTransaction $transaction): string
    {
        return $transaction->normalized_description
            ?? Str::of($transaction->description)->lower()->squish()->toString();
    }

    protected function amountsEqual(float $left, float $right): bool
    {
        return abs($left - $right) < 0.01;
    }
}
