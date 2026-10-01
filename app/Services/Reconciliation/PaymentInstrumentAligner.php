<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Builder;

class PaymentInstrumentAligner
{
    public function align(?string $orderLastFour, BankTransaction $transaction): bool
    {
        $transactionLastFour = $transaction->card_last_four;

        if ($orderLastFour === null || $orderLastFour === '' || $transactionLastFour === null || $transactionLastFour === '') {
            return true;
        }

        if ($orderLastFour === $transactionLastFour) {
            return true;
        }

        $account = $this->account($transaction);

        if (! $account instanceof Account) {
            return false;
        }

        $aliases = $account->cardAliases->pluck('last_four');

        if (! $aliases->contains($orderLastFour)) {
            return false;
        }

        if ($transactionLastFour === $account->last_four) {
            return true;
        }

        return $aliases->contains($transactionLastFour);
    }

    /**
     * Keep transactions whose card equals the order last four, or whose account
     * lists that last four as an alias of the card on the transaction.
     */
    public function applyLastFourConstraint(Builder $query, string $orderLastFour): void
    {
        $query->where(function (Builder $query) use ($orderLastFour): void {
            $query->where('card_last_four', $orderLastFour)
                ->orWhere(function (Builder $query) use ($orderLastFour): void {
                    $query->whereHas(
                        'account.cardAliases',
                        fn (Builder $alias) => $alias->where('last_four', $orderLastFour),
                    )->where(function (Builder $query): void {
                        $query->whereHas(
                            'account',
                            fn (Builder $account) => $account->whereColumn(
                                'accounts.last_four',
                                'bank_transactions.card_last_four',
                            ),
                        )->orWhereHas(
                            'account.cardAliases',
                            fn (Builder $alias) => $alias->whereColumn(
                                'account_card_aliases.last_four',
                                'bank_transactions.card_last_four',
                            ),
                        );
                    });
                });
        });
    }

    private function account(BankTransaction $transaction): ?Account
    {
        $account = $transaction->relationLoaded('account')
            ? $transaction->account
            : $transaction->account()->first();

        if (! $account instanceof Account) {
            return null;
        }

        $account->loadMissing('cardAliases');

        return $account;
    }
}
