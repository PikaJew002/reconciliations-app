<?php

namespace App\Services\Merchants;

use App\Models\BankTransaction;
use App\Models\Merchant;
use App\Models\MerchantMatchingRule;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

class MerchantMatchingRulePreviewService
{
    public function __construct(
        protected MerchantMatcher $matcher,
        protected int $listLimit = 50,
    ) {}

    /**
     * @return array{
     *     match_mode: string,
     *     pattern: string,
     *     duplicate_rule: array{merchant_id: int, merchant_name: string}|null,
     *     covered_count: int,
     *     missed_count: int,
     *     conflict_count: int,
     *     unassigned_count: int,
     *     missed: list<array<string, mixed>>,
     *     conflicts: list<array<string, mixed>>,
     *     unassigned: list<array<string, mixed>>,
     *     truncated: array{missed: bool, conflicts: bool, unassigned: bool}
     * }
     */
    public function preview(int $userId, Merchant $merchant, string $matchMode, string $pattern): array
    {
        $pattern = MerchantMatchingRule::normalizePattern($pattern);

        $duplicate = MerchantMatchingRule::query()
            ->where('user_id', $userId)
            ->where('match_mode', $matchMode)
            ->where('pattern', $pattern)
            ->with('merchant:id,name')
            ->first();

        $coveredCount = 0;
        $missedCount = 0;
        $conflictCount = 0;
        $unassignedCount = 0;
        $missed = [];
        $conflicts = [];
        $unassigned = [];

        foreach ($this->candidateTransactions($userId, $merchant, $matchMode, $pattern) as $transaction) {
            $matches = $this->matcher->proposedRuleMatches($transaction, $matchMode, $pattern);
            $isThisMerchant = $transaction->merchant_id === $merchant->id;

            if ($isThisMerchant && ! $matches) {
                $missedCount++;

                if (count($missed) < $this->listLimit) {
                    $missed[] = $this->serializeTransaction($transaction);
                }

                continue;
            }

            if (! $matches) {
                continue;
            }

            if ($isThisMerchant) {
                $coveredCount++;

                continue;
            }

            if ($transaction->merchant_id === null) {
                $unassignedCount++;

                if (count($unassigned) < $this->listLimit) {
                    $unassigned[] = $this->serializeTransaction($transaction);
                }

                continue;
            }

            $conflictCount++;

            if (count($conflicts) < $this->listLimit) {
                $conflicts[] = $this->serializeTransaction($transaction, includeMerchant: true);
            }
        }

        return [
            'match_mode' => $matchMode,
            'pattern' => $pattern,
            'duplicate_rule' => $duplicate === null ? null : [
                'merchant_id' => (int) $duplicate->merchant_id,
                'merchant_name' => (string) ($duplicate->merchant?->name ?? 'another merchant'),
            ],
            'covered_count' => $coveredCount,
            'missed_count' => $missedCount,
            'conflict_count' => $conflictCount,
            'unassigned_count' => $unassignedCount,
            'missed' => $missed,
            'conflicts' => $conflicts,
            'unassigned' => $unassigned,
            'truncated' => [
                'missed' => $missedCount > $this->listLimit,
                'conflicts' => $conflictCount > $this->listLimit,
                'unassigned' => $unassignedCount > $this->listLimit,
            ],
        ];
    }

    /**
     * @return LazyCollection<int, BankTransaction>
     */
    protected function candidateTransactions(
        int $userId,
        Merchant $merchant,
        string $matchMode,
        string $pattern,
    ): LazyCollection {
        $query = BankTransaction::query()
            ->where('user_id', $userId)
            ->with([
                'account:id,name,institution_name,last_four',
                'merchant:id,name',
            ])
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        if ($matchMode === MerchantMatchingRule::MATCH_CONTAINS && $pattern !== '') {
            $like = '%'.$this->escapeLike($pattern).'%';

            $query->where(function (Builder $builder) use ($merchant, $like): void {
                $builder
                    ->where('merchant_id', $merchant->id)
                    ->orWhere('normalized_description', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $query->cursor();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTransaction(BankTransaction $transaction, bool $includeMerchant = false): array
    {
        return [
            'id' => $transaction->id,
            'posted_at' => optional($transaction->posted_at)?->toDateString(),
            'description' => $transaction->description,
            'amount' => (float) $transaction->amount,
            'status' => $transaction->status,
            'account' => $transaction->account?->only(['id', 'name']),
            'merchant_name' => $includeMerchant ? $transaction->merchant?->name : null,
        ];
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
