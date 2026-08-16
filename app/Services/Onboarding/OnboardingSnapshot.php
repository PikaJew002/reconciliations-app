<?php

namespace App\Services\Onboarding;

use App\Models\User;
use Illuminate\Support\Facades\DB;

readonly class OnboardingSnapshot
{
    public function __construct(
        public bool $hasAccount,
        public ?string $firstAccountId,
        public bool $hasBankImport,
        public bool $hasOrderImport,
        public bool $hasCategorizedTransaction,
    ) {}

    public static function for(User $user): self
    {
        $row = DB::selectOne(
            'select
                exists(
                    select 1 from accounts
                    where user_id = ? and deleted_at is null
                ) as has_account,
                (
                    select id from accounts
                    where user_id = ? and deleted_at is null
                    order by name, id
                    limit 1
                ) as first_account_id,
                exists(
                    select 1 from import_batches
                    where user_id = ?
                        and source = ?
                        and type = ?
                        and status = ?
                        and record_count > 0
                ) as has_completed_bank_import,
                exists(
                    select 1 from bank_transactions
                    where user_id = ?
                ) as has_bank_transaction,
                exists(
                    select 1 from bank_transactions
                    where user_id = ?
                        and category_id is not null
                ) as has_categorized_transaction,
                exists(
                    select 1 from import_batches
                    where user_id = ?
                        and source in (?, ?)
                        and status = ?
                        and record_count > 0
                ) as has_completed_order_import,
                exists(
                    select 1 from orders
                    where user_id = ?
                ) as has_order',
            [
                $user->id,
                $user->id,
                $user->id,
                'bank',
                'transactions',
                'completed',
                $user->id,
                $user->id,
                $user->id,
                'amazon',
                'walmart',
                'completed',
                $user->id,
            ],
        );

        return new self(
            hasAccount: (bool) $row->has_account,
            firstAccountId: $row->first_account_id !== null ? (string) $row->first_account_id : null,
            hasBankImport: (bool) $row->has_completed_bank_import || (bool) $row->has_bank_transaction,
            hasOrderImport: (bool) $row->has_completed_order_import || (bool) $row->has_order,
            hasCategorizedTransaction: (bool) $row->has_categorized_transaction,
        );
    }
}
