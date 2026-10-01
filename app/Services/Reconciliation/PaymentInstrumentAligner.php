<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Builder;

class PaymentInstrumentAligner
{
    /**
     * @var array<string, bool>
     */
    private array $primaryIsChargeCard = [];

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

        if ($transactionLastFour === $account->last_four || $aliases->contains($transactionLastFour)) {
            return true;
        }

        // The account last four is often the bank account number, not the
        // number printed on charges. When it never appears on a charge, an
        // alias belongs to whatever card numbers this account actually posts.
        return ! $this->primaryLastFourIsChargeCard($account);
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
                        )->orWhereHas(
                            'account',
                            function (Builder $account): void {
                                $account->where(function (Builder $account): void {
                                    $account->whereNull('last_four')
                                        ->orWhereNotExists(function ($charges): void {
                                            $charges->selectRaw('1')
                                                ->from('bank_transactions as charge_cards')
                                                ->whereColumn('charge_cards.account_id', 'accounts.id')
                                                ->whereColumn('charge_cards.card_last_four', 'accounts.last_four');
                                        });
                                });
                            },
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

    private function primaryLastFourIsChargeCard(Account $account): bool
    {
        $key = (string) $account->id;

        if (array_key_exists($key, $this->primaryIsChargeCard)) {
            return $this->primaryIsChargeCard[$key];
        }

        if ($account->last_four === null || $account->last_four === '') {
            return $this->primaryIsChargeCard[$key] = false;
        }

        return $this->primaryIsChargeCard[$key] = BankTransaction::query()
            ->where('account_id', $account->id)
            ->where('card_last_four', $account->last_four)
            ->exists();
    }
}
