<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PendingSpend;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

class PendingSpendService
{
    public function __construct(
        protected PendingSpendMatcher $matcher,
    ) {}

    /**
     * @param  array{
     *     account_id: string,
     *     venmo?: bool,
     *     spent_at: mixed,
     *     amount: float|int|string,
     *     merchant_id?: int|null,
     *     category_id?: int|null,
     *     notes?: string|null
     * }  $attributes
     */
    public function create(User $user, array $attributes): PendingSpend
    {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->find($attributes['account_id'] ?? null);

        if ($account === null) {
            throw new InvalidArgumentException('Account is required and must belong to the user.');
        }

        $source = $this->resolveSource($account, (bool) ($attributes['venmo'] ?? false));

        $amount = round((float) ($attributes['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be a positive spend.');
        }

        $spentAt = Carbon::parse($attributes['spent_at'] ?? null);

        $merchant = $this->resolveMerchant($user, $source, $attributes['merchant_id'] ?? null);
        $category = $this->resolveCategory($user, $attributes['category_id'] ?? null);
        $classification = $category?->kind === Category::KIND_BILL
            ? BankTransaction::CLASSIFICATION_BILL
            : BankTransaction::CLASSIFICATION_EXPENSE;

        return PendingSpend::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant?->id,
            'category_id' => $category?->id,
            'source' => $source,
            'spent_at' => $spentAt,
            'amount' => $amount,
            'card_last_four' => $account->last_four,
            'classification' => $classification,
            'status' => PendingSpend::STATUS_PENDING,
            'review_reason' => null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }

    public function cancel(PendingSpend $pendingSpend): PendingSpend
    {
        if ($pendingSpend->isResolved()) {
            throw new InvalidArgumentException('Resolved pending spend cannot be cancelled.');
        }

        if ($pendingSpend->isCancelled()) {
            return $pendingSpend;
        }

        $pendingSpend->update([
            'status' => PendingSpend::STATUS_CANCELLED,
            'review_reason' => null,
        ]);

        return $pendingSpend->refresh();
    }

    public function link(PendingSpend $pendingSpend, BankTransaction $transaction): PendingSpend
    {
        $this->matcher->link($pendingSpend, $transaction);

        return $pendingSpend->refresh();
    }

    protected function resolveSource(Account $account, bool $venmo): string
    {
        if ($venmo) {
            if ($account->account_type === Account::CREDIT_CARD) {
                throw new InvalidArgumentException('Venmo pending spend cannot use a credit card account.');
            }

            if (! in_array($account->account_type, [Account::CHECKING, Account::SAVINGS], true)) {
                throw new InvalidArgumentException('Venmo pending spend requires a checking or savings account.');
            }

            return PendingSpend::SOURCE_VENMO;
        }

        if ($account->account_type === Account::CREDIT_CARD) {
            return PendingSpend::SOURCE_CREDIT_CARD;
        }

        if (in_array($account->account_type, [Account::CHECKING, Account::SAVINGS], true)) {
            return PendingSpend::SOURCE_DEBIT_CARD;
        }

        throw new InvalidArgumentException('Pending spend requires a checking, savings, or credit card account.');
    }

    protected function resolveMerchant(User $user, string $source, mixed $merchantId): ?Merchant
    {
        if ($merchantId === null || $merchantId === '') {
            if ($source !== PendingSpend::SOURCE_VENMO) {
                throw new InvalidArgumentException('Merchant is required for card pending spend.');
            }

            return null;
        }

        $merchant = Merchant::query()
            ->where('user_id', $user->id)
            ->find($merchantId);

        if ($merchant === null) {
            throw new InvalidArgumentException('Merchant must belong to the user.');
        }

        if ($merchant->supports_order_import) {
            throw new InvalidArgumentException('Order-import merchants are tracked via orders, not pending spend.');
        }

        return $merchant;
    }

    protected function resolveCategory(User $user, mixed $categoryId): ?Category
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $category = Category::query()
            ->where('user_id', $user->id)
            ->find($categoryId);

        if ($category === null) {
            throw new InvalidArgumentException('Category must belong to the user.');
        }

        if ($category->isIncome()) {
            throw new InvalidArgumentException('Pending spend cannot use an income category.');
        }

        return $category;
    }
}
