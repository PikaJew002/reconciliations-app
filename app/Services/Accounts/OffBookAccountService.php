<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Support\Facades\DB;

class OffBookAccountService
{
    public function ensureForUser(int $userId): Account
    {
        return DB::transaction(function () use ($userId): Account {
            $account = Account::withTrashed()
                ->where('user_id', $userId)
                ->offBook()
                ->lockForUpdate()
                ->first();

            if ($account === null) {
                $account = Account::query()->create([
                    'user_id' => $userId,
                    'name' => Account::OFF_BOOK_NAME,
                    'institution_name' => Account::OFF_BOOK_NAME,
                    'account_name' => null,
                    'account_type' => Account::OFF_BOOK,
                    'default_classification' => BankTransaction::CLASSIFICATION_EXPENSE,
                    'currency' => 'USD',
                    'last_four' => null,
                    'external_id' => Account::OFF_BOOK_EXTERNAL_ID,
                    'is_active' => true,
                ]);
            } else {
                if ($account->trashed()) {
                    $account->restore();
                }

                if (! $account->is_active) {
                    $account->update(['is_active' => true]);
                }
            }

            $this->reassignExistingTenders($userId, $account);

            return $account->refresh();
        });
    }

    protected function reassignExistingTenders(int $userId, Account $account): void
    {
        BankTransaction::query()
            ->where('user_id', $userId)
            ->where('metadata->source', 'non_bank_tender')
            ->where('account_id', '!=', $account->id)
            ->update(['account_id' => $account->id]);
    }
}
