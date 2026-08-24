<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\VenmoActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VenmoActivityMatcher
{
    public function __construct(
        protected int $dateWindowDays = 5,
        protected int $candidateWindowDays = 10,
    ) {}

    /**
     * @return array{confirmed: int, suggested: int, wallet_only: int}
     */
    public function matchForUser(int $userId): array
    {
        $confirmed = 0;
        $suggested = 0;
        $walletOnly = 0;

        $this->groupCashouts($userId);

        $activities = VenmoActivity::query()
            ->where('user_id', $userId)
            ->whereIn('match_status', [
                VenmoActivity::STATUS_UNMATCHED,
                VenmoActivity::STATUS_SUGGESTED,
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $claimedBankTransactionIds = VenmoActivity::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->where('match_status', VenmoActivity::STATUS_CONFIRMED)
            ->pluck('bank_transaction_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($activities as $activity) {
            if (! $activity->isBankFacing()) {
                if ($activity->isIncomingPayment() && $activity->cashed_out_by_activity_id === null) {
                    $activity->update(['match_status' => VenmoActivity::STATUS_WALLET_ONLY]);
                    $walletOnly++;
                }

                continue;
            }

            $candidates = $this->candidatesFor(
                $activity,
                $claimedBankTransactionIds,
                $this->dateWindowDays,
                requireLastFour: true,
            );

            if ($candidates->isEmpty()) {
                if ($activity->isSuggested()) {
                    $activity->update([
                        'bank_transaction_id' => null,
                        'match_status' => VenmoActivity::STATUS_UNMATCHED,
                    ]);
                }

                continue;
            }

            $best = $candidates->first();

            if ($candidates->count() === 1) {
                $activity->update([
                    'bank_transaction_id' => $best->id,
                    'match_status' => VenmoActivity::STATUS_CONFIRMED,
                ]);
                $claimedBankTransactionIds[] = $best->id;
                $confirmed++;

                continue;
            }

            $activity->update([
                'bank_transaction_id' => $best->id,
                'match_status' => VenmoActivity::STATUS_SUGGESTED,
            ]);
            $claimedBankTransactionIds[] = $best->id;
            $suggested++;
        }

        return [
            'confirmed' => $confirmed,
            'suggested' => $suggested,
            'wallet_only' => $walletOnly,
        ];
    }

    /**
     * @param  list<int>  $claimedBankTransactionIds
     * @return Collection<int, BankTransaction>
     */
    public function candidatesFor(
        VenmoActivity $activity,
        array $claimedBankTransactionIds = [],
        ?int $windowDays = null,
        bool $requireLastFour = false,
    ): Collection {
        $windowDays ??= $this->candidateWindowDays;
        $expectedAmount = $this->expectedBankAmount($activity);

        if ($expectedAmount === null) {
            return collect();
        }

        $occurredAt = $activity->occurred_at;

        if ($occurredAt === null) {
            return collect();
        }

        $from = $occurredAt->copy()->startOfDay()->subDays($windowDays);
        $to = $occurredAt->copy()->startOfDay()->addDays($windowDays);
        $rejectedIds = $activity->rejectedBankTransactionIds();
        $excludedIds = array_values(array_unique([...$claimedBankTransactionIds, ...$rejectedIds]));
        $lastFour = $activity->isDirectBankDebit()
            ? $activity->funding_last_four
            : $activity->destination_last_four;

        $query = BankTransaction::query()
            ->with('account:id,name,last_four')
            ->where('user_id', $activity->user_id)
            ->where('amount', $expectedAmount)
            ->whereBetween('posted_at', [$from->toDateString(), $to->toDateString()])
            ->where(function ($builder): void {
                $builder
                    ->where('description', 'like', '%venmo%')
                    ->orWhere('normalized_description', 'like', '%venmo%');
            });

        if ($excludedIds !== []) {
            $query->whereNotIn('id', $excludedIds);
        }

        if ($requireLastFour && $lastFour !== null) {
            if ($activity->isCardFunded()) {
                $query->where(function ($builder) use ($lastFour): void {
                    $builder
                        ->where('card_last_four', $lastFour)
                        ->orWhereHas('account', fn ($accountQuery) => $accountQuery->where('last_four', $lastFour));
                });
            } else {
                $query->whereHas('account', fn ($accountQuery) => $accountQuery->where('last_four', $lastFour));
            }
        }

        $targetDate = $occurredAt->toDateString();

        return $query
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get()
            ->sortBy(function (BankTransaction $transaction) use ($targetDate): array {
                $posted = $transaction->posted_at?->toDateString() ?? $targetDate;
                $diff = abs(strtotime($posted) - strtotime($targetDate));

                return [$diff, $transaction->id];
            })
            ->values();
    }

    /**
     * Candidates for manual review, including amount-only Venmo lines in a wider window.
     *
     * @return Collection<int, BankTransaction>
     */
    public function reviewCandidatesFor(VenmoActivity $activity): Collection
    {
        $claimed = $this->confirmedBankTransactionIds($activity->user_id);
        $strict = $this->candidatesFor($activity, $claimed, $this->candidateWindowDays, requireLastFour: true);

        if ($strict->isNotEmpty()) {
            return $strict;
        }

        return $this->candidatesFor($activity, $claimed, $this->candidateWindowDays, requireLastFour: false);
    }

    public function confirm(VenmoActivity $activity): void
    {
        if (! $activity->isSuggested() || $activity->bank_transaction_id === null) {
            throw new InvalidArgumentException('Only suggested Venmo matches can be confirmed.');
        }

        $activity->update([
            'match_status' => VenmoActivity::STATUS_CONFIRMED,
        ]);
    }

    public function reject(VenmoActivity $activity): void
    {
        if (! $activity->isSuggested() || $activity->bank_transaction_id === null) {
            throw new InvalidArgumentException('Only suggested Venmo matches can be dismissed.');
        }

        $rejected = $activity->rejectedBankTransactionIds();
        $rejected[] = (int) $activity->bank_transaction_id;

        $activity->update([
            'bank_transaction_id' => null,
            'match_status' => VenmoActivity::STATUS_UNMATCHED,
            'metadata' => array_merge($activity->metadata ?? [], [
                'rejected_bank_transaction_ids' => array_values(array_unique($rejected)),
            ]),
        ]);
    }

    public function assign(VenmoActivity $activity, BankTransaction $transaction): void
    {
        if ($transaction->user_id !== $activity->user_id) {
            throw new InvalidArgumentException('Bank transaction does not belong to this Venmo activity.');
        }

        $alreadyConfirmed = VenmoActivity::query()
            ->where('user_id', $activity->user_id)
            ->where('bank_transaction_id', $transaction->id)
            ->where('match_status', VenmoActivity::STATUS_CONFIRMED)
            ->where('id', '!=', $activity->id)
            ->exists();

        if ($alreadyConfirmed) {
            throw new InvalidArgumentException('That bank transaction is already linked to another Venmo activity.');
        }

        $activity->update([
            'bank_transaction_id' => $transaction->id,
            'match_status' => VenmoActivity::STATUS_CONFIRMED,
        ]);
    }

    protected function groupCashouts(int $userId): void
    {
        $transfers = VenmoActivity::query()
            ->where('user_id', $userId)
            ->where('type', 'like', '%transfer%')
            ->where('amount', '<', 0)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $incoming = VenmoActivity::query()
            ->where('user_id', $userId)
            ->where('type', VenmoActivity::TYPE_PAYMENT)
            ->where('amount', '>', 0)
            ->whereNull('cashed_out_by_activity_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->values();

        foreach ($transfers as $transfer) {
            $target = round(abs((float) $transfer->amount), 2);
            $usedIndexes = [];
            $sum = 0.0;

            foreach ($incoming as $index => $payment) {
                if ($payment->occurred_at === null || $transfer->occurred_at === null) {
                    continue;
                }

                if ($payment->occurred_at->gt($transfer->occurred_at)) {
                    continue;
                }

                $next = round($sum + (float) $payment->amount, 2);

                if ($next > $target + 0.001) {
                    continue;
                }

                $usedIndexes[] = $index;
                $sum = $next;

                if (abs($sum - $target) < 0.01) {
                    break;
                }
            }

            if (abs($sum - $target) >= 0.01) {
                continue;
            }

            DB::transaction(function () use ($transfer, $incoming, $usedIndexes): void {
                foreach ($usedIndexes as $index) {
                    $incoming[$index]->update([
                        'cashed_out_by_activity_id' => $transfer->id,
                    ]);
                }
            });

            foreach (array_reverse($usedIndexes) as $index) {
                $incoming->forget($index);
            }

            $incoming = $incoming->values();
        }
    }

    protected function expectedBankAmount(VenmoActivity $activity): ?float
    {
        $amount = round((float) $activity->amount, 2);

        if ($activity->isDirectBankDebit()) {
            return $amount;
        }

        if (str_contains($activity->type, 'transfer') && $amount < 0) {
            return round(abs($amount), 2);
        }

        return null;
    }

    /**
     * @return list<int>
     */
    protected function confirmedBankTransactionIds(int $userId): array
    {
        return VenmoActivity::query()
            ->where('user_id', $userId)
            ->whereNotNull('bank_transaction_id')
            ->where('match_status', VenmoActivity::STATUS_CONFIRMED)
            ->pluck('bank_transaction_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
