<?php

namespace App\Services\Reconciliation;

use App\Models\BankTransaction;
use App\Models\TransactionCategorizationRule;
use Illuminate\Support\Str;

class TransactionMatchEvaluator
{
    public function matchesRule(BankTransaction $transaction, TransactionCategorizationRule $rule): bool
    {
        return $this->matches(
            $transaction,
            $rule->match_mode,
            $rule->normalized_pattern,
            $rule->merchant_id !== null ? (int) $rule->merchant_id : null,
            $rule->amount,
            $rule->classification,
        );
    }

    public function matches(
        BankTransaction $transaction,
        string $matchMode,
        ?string $normalizedPattern,
        ?int $merchantId,
        mixed $amount,
        ?string $classification = null,
    ): bool {
        $normalized = $this->normalizedDescription($transaction);
        $txAmount = round(abs((float) $transaction->amount), 2);
        $ruleAmount = $amount !== null ? round(abs((float) $amount), 2) : null;

        return match ($matchMode) {
            TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT => $normalized !== ''
                && $normalizedPattern === $normalized
                && $ruleAmount !== null
                && abs($ruleAmount - $txAmount) < 0.01,
            TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT => $transaction->merchant_id !== null
                && $merchantId === (int) $transaction->merchant_id
                && $ruleAmount !== null
                && abs($ruleAmount - $txAmount) < 0.01,
            TransactionCategorizationRule::MATCH_MERCHANT => $transaction->merchant_id !== null
                && $merchantId === (int) $transaction->merchant_id,
            TransactionCategorizationRule::MATCH_DESCRIPTION => $normalized !== ''
                && $normalizedPattern === $normalized,
            TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT => $classification === BankTransaction::CLASSIFICATION_BILL
                && $this->isCheckDescription($normalized)
                && $ruleAmount !== null
                && abs($ruleAmount - $txAmount) < 0.01,
            TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT => $classification === BankTransaction::CLASSIFICATION_BILL
                && is_string($normalizedPattern)
                && $normalizedPattern !== ''
                && $this->descriptionMatchesPrefix($normalized, $normalizedPattern)
                && $ruleAmount !== null
                && abs($ruleAmount - $txAmount) < 0.01,
            default => false,
        };
    }

    public function normalizedDescription(BankTransaction $transaction): string
    {
        $value = $transaction->normalized_description ?: $transaction->description;

        return Str::of((string) $value)->lower()->squish()->toString();
    }

    public function descriptionMatchesPrefix(string $normalized, string $prefix): bool
    {
        $prefix = Str::of($prefix)->lower()->squish()->toString();

        if ($prefix === '' || $normalized === '') {
            return false;
        }

        return $normalized === $prefix || str_starts_with($normalized, $prefix.' ');
    }

    public function isCheckDescription(string $normalized): bool
    {
        return $normalized !== ''
            && str_starts_with($normalized, TransactionCategorizationRule::CHECK_DESCRIPTION_PREFIX);
    }
}
