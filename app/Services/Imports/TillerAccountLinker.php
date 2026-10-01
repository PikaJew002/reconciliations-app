<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\User;

class TillerAccountLinker
{
    public function resolve(User $user, string $tillerAccountId, string $accountNumber): ?Account
    {
        $linked = Account::query()
            ->where('user_id', $user->id)
            ->where('external_id', $tillerAccountId)
            ->first();

        if ($linked instanceof Account) {
            return $linked->isOffBook() ? null : $linked;
        }

        $candidates = Account::query()
            ->where('user_id', $user->id)
            ->whereNull('external_id')
            ->where('last_four', $accountNumber)
            ->get();

        if ($candidates->count() !== 1) {
            return null;
        }

        $account = $candidates->first();

        if (! $account instanceof Account || $account->isOffBook()) {
            return null;
        }

        $account->update([
            'external_id' => $tillerAccountId,
        ]);

        return $account->fresh();
    }
}
