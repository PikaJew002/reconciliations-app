<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ReimbursementGroup;
use App\Models\ReimbursementGroupTransaction;
use App\Models\TransactionTransferLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReimbursementGroupService
{
    /**
     * @param  list<int>  $transactionIds
     */
    public function create(int $userId, array $transactionIds, ?string $name = null, ?string $notes = null): ReimbursementGroup
    {
        $transactionIds = $this->uniqueIds($transactionIds);

        if ($transactionIds === []) {
            throw new InvalidArgumentException('Select at least one transaction.');
        }

        return DB::transaction(function () use ($userId, $transactionIds, $name, $notes) {
            $group = ReimbursementGroup::query()->create([
                'user_id' => $userId,
                'name' => $name,
                'notes' => $notes,
                'status' => ReimbursementGroup::STATUS_OPEN,
            ]);

            $this->attachTransactions($group, $transactionIds);

            return $group->load(['legs.bankTransaction', 'remainderCategory']);
        });
    }

    /**
     * @param  list<int>  $transactionIds
     */
    public function addTransactions(ReimbursementGroup $group, array $transactionIds): ReimbursementGroup
    {
        $this->assertOpen($group);

        $transactionIds = $this->uniqueIds($transactionIds);

        if ($transactionIds === []) {
            throw new InvalidArgumentException('Select at least one transaction.');
        }

        return DB::transaction(function () use ($group, $transactionIds) {
            $this->attachTransactions($group, $transactionIds);

            return $group->fresh(['legs.bankTransaction', 'remainderCategory']);
        });
    }

    public function removeTransaction(ReimbursementGroup $group, BankTransaction $transaction): ReimbursementGroup
    {
        $this->assertOpen($group);

        return DB::transaction(function () use ($group, $transaction) {
            $leg = ReimbursementGroupTransaction::query()
                ->where('reimbursement_group_id', $group->id)
                ->where('bank_transaction_id', $transaction->id)
                ->first();

            if ($leg === null) {
                throw new InvalidArgumentException('Transaction is not in this reimbursement group.');
            }

            $this->restoreTransaction($transaction, $leg->prior_state ?? []);
            $leg->delete();

            return $group->fresh(['legs.bankTransaction', 'remainderCategory']);
        });
    }

    public function updateLegAmount(
        ReimbursementGroup $group,
        BankTransaction $transaction,
        float $amount,
    ): ReimbursementGroup {
        $this->assertOpen($group);

        if ($amount < 0.01) {
            throw new InvalidArgumentException('Leg amount must be at least 0.01.');
        }

        return DB::transaction(function () use ($group, $transaction, $amount) {
            $leg = ReimbursementGroupTransaction::query()
                ->where('reimbursement_group_id', $group->id)
                ->where('bank_transaction_id', $transaction->id)
                ->first();

            if ($leg === null) {
                throw new InvalidArgumentException('Transaction is not in this reimbursement group.');
            }

            $max = round(abs((float) $transaction->amount), 2);

            if (round($amount, 2) - $max > 0.001) {
                throw new InvalidArgumentException('Leg amount cannot exceed the transaction amount.');
            }

            $leg->update(['amount' => round($amount, 2)]);

            return $group->fresh(['legs.bankTransaction', 'remainderCategory']);
        });
    }

    public function close(
        ReimbursementGroup $group,
        ?int $remainderCategoryId = null,
        string $remainderClassification = BankTransaction::CLASSIFICATION_EXPENSE,
    ): ReimbursementGroup {
        $this->assertOpen($group);

        $group->loadMissing('legs');
        $net = $group->net();

        if ($net <= -0.01) {
            if ($remainderClassification !== BankTransaction::CLASSIFICATION_INCOME) {
                throw new InvalidArgumentException(
                    'Over-reimbursed groups must book the surplus as income.',
                );
            }

            $remainderCategoryId = null;
            $remainderClassification = BankTransaction::CLASSIFICATION_INCOME;
        } elseif ($net >= 0.01) {
            if ($remainderCategoryId === null) {
                throw new InvalidArgumentException('Choose a remainder category for the unreimbursed amount.');
            }

            if (! in_array($remainderClassification, [
                BankTransaction::CLASSIFICATION_EXPENSE,
                BankTransaction::CLASSIFICATION_BILL,
            ], true)) {
                throw new InvalidArgumentException('Remainder classification must be expense or bill.');
            }

            $category = Category::query()
                ->where('user_id', $group->user_id)
                ->where('id', $remainderCategoryId)
                ->where('is_active', true)
                ->first();

            if ($category === null) {
                throw new InvalidArgumentException('Remainder category not found.');
            }

            $expectedKind = $remainderClassification === BankTransaction::CLASSIFICATION_BILL
                ? Category::KIND_BILL
                : Category::KIND_EXPENSE;

            if ($category->kind !== $expectedKind) {
                throw new InvalidArgumentException('Remainder category kind must match classification.');
            }
        } else {
            $remainderCategoryId = null;
            $remainderClassification = null;
        }

        $group->update([
            'status' => ReimbursementGroup::STATUS_CLOSED,
            'remainder_category_id' => $remainderCategoryId,
            'remainder_classification' => $remainderClassification,
            'closed_at' => now(),
        ]);

        return $group->fresh(['legs.bankTransaction', 'remainderCategory']);
    }

    public function reopen(ReimbursementGroup $group): ReimbursementGroup
    {
        if (! $group->isClosed()) {
            throw new InvalidArgumentException('Only closed reimbursement groups can be reopened.');
        }

        $group->update([
            'status' => ReimbursementGroup::STATUS_OPEN,
            'remainder_category_id' => null,
            'remainder_classification' => null,
            'closed_at' => null,
        ]);

        return $group->fresh(['legs.bankTransaction', 'remainderCategory']);
    }

    public function destroy(ReimbursementGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->loadMissing('legs.bankTransaction');

            foreach ($group->legs as $leg) {
                if ($leg->bankTransaction) {
                    $this->restoreTransaction($leg->bankTransaction, $leg->prior_state ?? []);
                }
            }

            $group->legs()->delete();
            $group->delete();
        });
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    public function eligibleTransactionsForUser(int $userId, ?int $excludeGroupId = null): Collection
    {
        $groupedIds = ReimbursementGroupTransaction::query()
            ->whereHas('group', fn ($query) => $query->where('user_id', $userId))
            ->when(
                $excludeGroupId !== null,
                fn ($query) => $query->where('reimbursement_group_id', '!=', $excludeGroupId),
            )
            ->pluck('bank_transaction_id')
            ->all();

        $transferLinkedIds = TransactionTransferLink::query()
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

        $excluded = array_values(array_unique([...$groupedIds, ...$transferLinkedIds]));

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', '!=', 0)
            ->whereNotIn('status', ['matched', 'partial'])
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->where(function ($query): void {
                $query
                    ->where(function ($unmatched): void {
                        $unmatched->where('status', 'unmatched')
                            ->where(function ($classification): void {
                                $classification->whereNull('classification')
                                    ->orWhere('classification', BankTransaction::CLASSIFICATION_INCOME);
                            });
                    })
                    ->orWhere(function ($categorized): void {
                        $categorized->where('status', 'ignored')
                            ->whereIn('classification', [
                                BankTransaction::CLASSIFICATION_BILL,
                                BankTransaction::CLASSIFICATION_EXPENSE,
                                BankTransaction::CLASSIFICATION_INCOME,
                            ]);
                    });
            })
            ->with(['merchant:id,name', 'account:id,name,last_four'])
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get();
    }

    /**
     * @param  list<int>  $transactionIds
     */
    protected function attachTransactions(ReimbursementGroup $group, array $transactionIds): void
    {
        $transactions = BankTransaction::query()
            ->where('user_id', $group->user_id)
            ->whereIn('id', $transactionIds)
            ->get()
            ->keyBy('id');

        if ($transactions->count() !== count($transactionIds)) {
            throw new InvalidArgumentException('One or more transactions were not found.');
        }

        foreach ($transactionIds as $transactionId) {
            /** @var BankTransaction $transaction */
            $transaction = $transactions->get($transactionId);
            $this->assertEligible($group, $transaction);

            $amount = round(abs((float) $transaction->amount), 2);
            $role = (float) $transaction->amount < 0
                ? ReimbursementGroupTransaction::ROLE_EXPENSE
                : ReimbursementGroupTransaction::ROLE_REIMBURSEMENT;

            $priorState = [
                'status' => $transaction->status,
                'classification' => $transaction->classification,
                'classification_source' => $transaction->classification_source,
                'classification_confidence' => $transaction->classification_confidence,
                'category_id' => $transaction->category_id,
            ];

            ReimbursementGroupTransaction::query()->create([
                'reimbursement_group_id' => $group->id,
                'bank_transaction_id' => $transaction->id,
                'role' => $role,
                'amount' => $amount,
                'prior_state' => $priorState,
            ]);

            if ($role === ReimbursementGroupTransaction::ROLE_REIMBURSEMENT) {
                $transaction->update([
                    'classification' => BankTransaction::CLASSIFICATION_REIMBURSEMENT,
                    'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_PAIRED,
                    'classification_confidence' => 100,
                    'status' => 'ignored',
                    'category_id' => null,
                ]);
            } else {
                $transaction->update([
                    'status' => 'ignored',
                ]);
            }
        }
    }

    protected function assertEligible(ReimbursementGroup $group, BankTransaction $transaction): void
    {
        if ($transaction->user_id !== $group->user_id) {
            throw new InvalidArgumentException('Transaction does not belong to this user.');
        }

        if (in_array($transaction->status, ['matched', 'partial'], true)) {
            throw new InvalidArgumentException('Matched transactions cannot join a reimbursement group.');
        }

        if ((float) $transaction->amount == 0.0) {
            throw new InvalidArgumentException('Zero-amount transactions cannot join a reimbursement group.');
        }

        if ($transaction->reimbursementGroupLeg()->exists()) {
            throw new InvalidArgumentException('Transaction is already in a reimbursement group.');
        }

        $inTransfer = TransactionTransferLink::query()
            ->where(function ($query) use ($transaction): void {
                $query->where('debit_transaction_id', $transaction->id)
                    ->orWhere('credit_transaction_id', $transaction->id);
            })
            ->whereIn('status', [
                TransactionTransferLink::STATUS_SUGGESTED,
                TransactionTransferLink::STATUS_CONFIRMED,
            ])
            ->exists();

        if ($inTransfer) {
            throw new InvalidArgumentException('Transaction is part of a transfer link.');
        }
    }

    /**
     * @param  array<string, mixed>  $priorState
     */
    protected function restoreTransaction(BankTransaction $transaction, array $priorState): void
    {
        $transaction->update([
            'status' => $priorState['status'] ?? 'unmatched',
            'classification' => $priorState['classification'] ?? null,
            'classification_source' => $priorState['classification_source'] ?? null,
            'classification_confidence' => $priorState['classification_confidence'] ?? null,
            'category_id' => $priorState['category_id'] ?? null,
        ]);
    }

    protected function assertOpen(ReimbursementGroup $group): void
    {
        if (! $group->isOpen()) {
            throw new InvalidArgumentException('Only open reimbursement groups can be edited.');
        }
    }

    /**
     * @param  list<int>  $transactionIds
     * @return list<int>
     */
    protected function uniqueIds(array $transactionIds): array
    {
        return array_values(array_unique(array_map('intval', $transactionIds)));
    }
}
