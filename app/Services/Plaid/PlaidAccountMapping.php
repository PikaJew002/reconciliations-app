<?php

namespace App\Services\Plaid;

use App\Jobs\SyncPlaidTransactions;
use App\Models\Account;
use App\Models\PlaidAccount;
use App\Models\PlaidItem;
use App\Models\User;
use Illuminate\Support\Collection;

class PlaidAccountMapping
{
    /**
     * @return array{
     *     item: array<string, mixed>,
     *     plaidAccounts: list<array<string, mixed>>,
     *     spendableAccounts: list<array<string, mixed>>
     * }
     */
    public function page(PlaidItem $item, User $user): array
    {
        $item->load('accounts');
        $spendable = Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->orderBy('name')
            ->get();

        return [
            'item' => [
                'id' => $item->id,
                'institution_name' => $item->institution_name,
                'import_from' => $item->import_from->toDateString(),
                'days_requested' => $item->days_requested,
                'cursor_started' => $item->cursor !== null,
            ],
            'plaidAccounts' => $item->accounts->map(function (PlaidAccount $account) use ($spendable): array {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'official_name' => $account->official_name,
                    'mask' => $account->mask,
                    'type' => $account->type,
                    'subtype' => $account->subtype,
                    'account_id' => $account->account_id,
                    'suggested_account_id' => $this->suggestion($account, $spendable),
                ];
            })->values()->all(),
            'spendableAccounts' => $spendable->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'last_four' => $account->last_four,
                'account_type' => $account->account_type,
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array{id: string, account_id: ?string}>  $mappings
     */
    public function save(PlaidItem $item, User $user, array $mappings): void
    {
        $item->load('accounts');
        $allowed = Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->pluck('id');

        foreach ($mappings as $mapping) {
            $plaidAccount = $item->accounts->firstWhere('id', $mapping['id']);

            if (! $plaidAccount instanceof PlaidAccount) {
                continue;
            }

            $accountId = $mapping['account_id'] ?? null;

            if ($accountId !== null && ! $allowed->contains($accountId)) {
                continue;
            }

            $plaidAccount->update([
                'account_id' => $accountId,
            ]);
        }

        $item->unsetRelation('accounts');

        if ($item->cursor === null && $item->accounts()->whereNotNull('account_id')->exists()) {
            SyncPlaidTransactions::dispatch($item->id);
        }
    }

    /**
     * @param  Collection<int, Account>  $spendable
     */
    protected function suggestion(PlaidAccount $account, Collection $spendable): ?string
    {
        if ($account->mask === null) {
            return null;
        }

        $matches = $spendable->where('last_four', $account->mask)->values();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first()->id;
    }
}
