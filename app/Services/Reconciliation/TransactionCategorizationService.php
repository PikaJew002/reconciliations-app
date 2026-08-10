<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TransactionCategorizationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TransactionCategorizationService
{
    /**
     * @return array{applied: int, ambiguous: int}
     */
    public function categorizeForUser(int $userId): array
    {
        $applied = 0;
        $ambiguous = 0;

        $rules = TransactionCategorizationRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('match_mode', TransactionCategorizationRule::persistableMatchModes())
            ->with('category')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return ['applied' => 0, 'ambiguous' => 0];
        }

        BankTransaction::query()
            ->where('user_id', $userId)
            ->availableForExpenseMatching()
            ->where('amount', '<', 0)
            ->whereDoesntHave('merchant', fn ($query) => $query->where('supports_order_import', true))
            ->with('merchant')
            ->orderBy('id')
            ->each(function (BankTransaction $transaction) use ($rules, &$applied, &$ambiguous): void {
                $matches = $this->matchingRules($transaction, $rules);

                if ($matches->isEmpty()) {
                    return;
                }

                if ($matches->count() > 1) {
                    $uniqueTargets = $matches
                        ->map(fn (TransactionCategorizationRule $rule) => $rule->classification.'|'.$rule->category_id)
                        ->unique()
                        ->count();

                    if ($uniqueTargets > 1) {
                        $ambiguous++;

                        return;
                    }
                }

                /** @var TransactionCategorizationRule $rule */
                $rule = $matches->first();
                $this->applyRule($transaction, $rule, BankTransaction::CLASSIFICATION_SOURCE_LEARNED);
                $applied++;
            });

        return [
            'applied' => $applied,
            'ambiguous' => $ambiguous,
        ];
    }

    public function categorizeTransaction(
        BankTransaction $transaction,
        Category $category,
        string $classification,
        string $matchMode,
    ): void {
        if ((float) $transaction->amount >= 0) {
            throw new InvalidArgumentException('Only debit transactions can be categorized as bills or expenses.');
        }

        if ($transaction->user_id !== $category->user_id) {
            throw new InvalidArgumentException('Category does not belong to this transaction’s user.');
        }

        if (! in_array($classification, [
            BankTransaction::CLASSIFICATION_BILL,
            BankTransaction::CLASSIFICATION_EXPENSE,
        ], true)) {
            throw new InvalidArgumentException('Classification must be bill or expense.');
        }

        $expectedKind = $classification === BankTransaction::CLASSIFICATION_BILL
            ? Category::KIND_BILL
            : Category::KIND_EXPENSE;

        if ($category->kind !== $expectedKind) {
            throw new InvalidArgumentException('Category kind must match classification.');
        }

        if ($transaction->merchant?->supports_order_import) {
            throw new InvalidArgumentException('Order-import merchant transactions cannot be categorized at the transaction level.');
        }

        if (! in_array($matchMode, TransactionCategorizationRule::allMatchModes(), true)) {
            throw new InvalidArgumentException('Invalid match mode.');
        }

        if (
            in_array($matchMode, TransactionCategorizationRule::billOnlyMatchModes(), true)
            && $classification !== BankTransaction::CLASSIFICATION_BILL
        ) {
            throw new InvalidArgumentException('Check + amount matching is only available for bills.');
        }

        if (
            $matchMode === TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT
            && ! $this->isCheckDescription($this->normalizedDescription($transaction))
        ) {
            throw new InvalidArgumentException('Check + amount matching requires a description that starts with "CHECK ".');
        }

        $transaction->update([
            'classification' => $classification,
            'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_MANUAL,
            'classification_confidence' => 100,
            'category_id' => $category->id,
            'status' => 'ignored',
        ]);

        if ($matchMode === TransactionCategorizationRule::MATCH_ONCE) {
            return;
        }

        $this->upsertRule($transaction, $category, $classification, $matchMode);
    }

    /**
     * @param  Collection<int, TransactionCategorizationRule>  $rules
     * @return Collection<int, TransactionCategorizationRule>
     */
    protected function matchingRules(BankTransaction $transaction, Collection $rules): Collection
    {
        $normalized = $this->normalizedDescription($transaction);
        $amount = round(abs((float) $transaction->amount), 2);

        return $rules->filter(function (TransactionCategorizationRule $rule) use ($transaction, $normalized, $amount) {
            return match ($rule->match_mode) {
                TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT => $normalized !== ''
                    && $rule->normalized_pattern === $normalized
                    && $rule->amount !== null
                    && abs((float) $rule->amount - $amount) < 0.01,
                TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT => $transaction->merchant_id !== null
                    && $rule->merchant_id === $transaction->merchant_id
                    && $rule->amount !== null
                    && abs((float) $rule->amount - $amount) < 0.01,
                TransactionCategorizationRule::MATCH_MERCHANT => $transaction->merchant_id !== null
                    && $rule->merchant_id === $transaction->merchant_id,
                TransactionCategorizationRule::MATCH_DESCRIPTION => $normalized !== ''
                    && $rule->normalized_pattern === $normalized,
                TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT => $rule->classification === BankTransaction::CLASSIFICATION_BILL
                    && $this->isCheckDescription($normalized)
                    && $rule->amount !== null
                    && abs((float) $rule->amount - $amount) < 0.01,
                default => false,
            };
        })->values();
    }

    protected function applyRule(
        BankTransaction $transaction,
        TransactionCategorizationRule $rule,
        string $source,
    ): void {
        $transaction->update([
            'classification' => $rule->classification,
            'classification_source' => $source,
            'classification_confidence' => 100,
            'category_id' => $rule->category_id,
            'status' => 'ignored',
        ]);
    }

    protected function upsertRule(
        BankTransaction $transaction,
        Category $category,
        string $classification,
        string $matchMode,
    ): void {
        $normalized = $this->normalizedDescription($transaction);
        $amount = round(abs((float) $transaction->amount), 2);

        $attributes = [
            'user_id' => $transaction->user_id,
            'classification' => $classification,
            'match_mode' => $matchMode,
            'merchant_id' => null,
            'normalized_pattern' => null,
            'amount' => null,
        ];

        if ($matchMode === TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT) {
            $attributes['normalized_pattern'] = $normalized !== '' ? $normalized : null;
            $attributes['amount'] = $amount;
        } elseif ($matchMode === TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT) {
            $attributes['merchant_id'] = $transaction->merchant_id;
            $attributes['amount'] = $amount;
        } elseif ($matchMode === TransactionCategorizationRule::MATCH_MERCHANT) {
            $attributes['merchant_id'] = $transaction->merchant_id;
        } elseif ($matchMode === TransactionCategorizationRule::MATCH_DESCRIPTION) {
            $attributes['normalized_pattern'] = $normalized !== '' ? $normalized : null;
        } elseif ($matchMode === TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT) {
            if ($classification !== BankTransaction::CLASSIFICATION_BILL || ! $this->isCheckDescription($normalized)) {
                return;
            }

            $attributes['normalized_pattern'] = TransactionCategorizationRule::CHECK_DESCRIPTION_PREFIX;
            $attributes['amount'] = $amount;
        }

        if (
            in_array($matchMode, [
                TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT,
                TransactionCategorizationRule::MATCH_MERCHANT,
            ], true) && $attributes['merchant_id'] === null
        ) {
            return;
        }

        if (
            in_array($matchMode, [
                TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
                TransactionCategorizationRule::MATCH_DESCRIPTION,
                TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT,
            ], true) && ($attributes['normalized_pattern'] === null || $attributes['normalized_pattern'] === '')
        ) {
            return;
        }

        TransactionCategorizationRule::query()->updateOrCreate(
            [
                'user_id' => $attributes['user_id'],
                'classification' => $attributes['classification'],
                'match_mode' => $attributes['match_mode'],
                'merchant_id' => $attributes['merchant_id'],
                'normalized_pattern' => $attributes['normalized_pattern'],
                'amount' => $attributes['amount'],
            ],
            [
                'category_id' => $category->id,
                'is_active' => true,
            ],
        );
    }

    protected function normalizedDescription(BankTransaction $transaction): string
    {
        $value = $transaction->normalized_description ?: $transaction->description;

        return Str::of((string) $value)->lower()->squish()->toString();
    }

    protected function isCheckDescription(string $normalized): bool
    {
        return $normalized !== ''
            && str_starts_with($normalized, TransactionCategorizationRule::CHECK_DESCRIPTION_PREFIX);
    }
}
