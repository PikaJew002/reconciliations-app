<?php

namespace App\Services\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\TransactionTransferLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditCardPaymentPairingService
{
    /**
     * @var list<string>
     */
    protected array $paymentTokens = [
        'payment',
        'pmt',
        'pymt',
    ];

    /**
     * @var list<string>
     */
    protected array $cardIssuerTokens = [
        'capital one',
        'mastercard',
        'visa',
        'amex',
        'american express',
        'discover',
        'credit card',
        'card payment',
    ];

    /**
     * @var list<string>
     */
    protected array $nonPaymentCreditTokens = [
        'cash back',
        'cashback',
        'reward',
        'interest',
        'refund',
        'adjustment',
    ];

    public function __construct(
        protected int $dateWindowDays = 3,
    ) {}

    /**
     * @return array{confirmed: int, suggested: int}
     */
    public function pairForUser(int $userId): array
    {
        $confirmed = 0;
        $suggested = 0;

        $transactions = $this->eligibleTransactions($userId);

        if ($transactions->isEmpty()) {
            return ['confirmed' => 0, 'suggested' => 0];
        }

        $creditCardInstitutionTokens = $transactions
            ->filter(fn (BankTransaction $txn): bool => $txn->account?->account_type === Account::CREDIT_CARD)
            ->map(function (BankTransaction $txn): string {
                return Str::of($txn->account?->institution_name ?? '')
                    ->lower()
                    ->squish()
                    ->toString();
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $debits = $transactions
            ->filter(function (BankTransaction $txn) use ($creditCardInstitutionTokens): bool {
                return (float) $txn->amount < 0
                    && in_array($txn->account?->account_type, [Account::CHECKING, Account::SAVINGS], true)
                    && $this->looksLikeCardPaymentDebit($txn, $creditCardInstitutionTokens);
            })
            ->values();

        $credits = $transactions
            ->filter(function (BankTransaction $txn): bool {
                return (float) $txn->amount > 0
                    && $txn->account?->account_type === Account::CREDIT_CARD
                    && $this->looksLikeCardPaymentCredit($txn);
            })
            ->values();

        if ($debits->isEmpty() || $credits->isEmpty()) {
            return ['confirmed' => 0, 'suggested' => 0];
        }

        $pairs = $this->resolvePairs($debits, $credits);

        foreach ($pairs as $pair) {
            $confidence = $this->confidenceForPair($pair['debit'], $pair['credit'], $pair['gap']);

            if ($this->shouldAutoConfirm($pair['gap'])) {
                $this->confirmPair($userId, $pair['debit'], $pair['credit'], $confidence);
                $confirmed++;

                continue;
            }

            $this->suggestPair($userId, $pair['debit'], $pair['credit'], $confidence);
            $suggested++;
        }

        return [
            'confirmed' => $confirmed,
            'suggested' => $suggested,
        ];
    }

    /**
     * @return Collection<int, BankTransaction>
     */
    protected function eligibleTransactions(int $userId): Collection
    {
        $linkedIds = TransactionTransferLink::query()
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

        return BankTransaction::query()
            ->where('user_id', $userId)
            ->where('status', 'unmatched')
            ->whereNull('classification')
            ->whereNull('transfer_group_id')
            ->when($linkedIds !== [], fn ($query) => $query->whereNotIn('id', $linkedIds))
            ->whereHas('account', function ($query): void {
                $query->whereIn('account_type', [
                    Account::CHECKING,
                    Account::SAVINGS,
                    Account::CREDIT_CARD,
                ]);
            })
            ->with('account:id,account_type,last_four,institution_name')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, BankTransaction>  $debits
     * @param  Collection<int, BankTransaction>  $credits
     * @return list<array{debit: BankTransaction, credit: BankTransaction, gap: int}>
     */
    protected function resolvePairs(Collection $debits, Collection $credits): array
    {
        $candidates = [];

        foreach ($debits as $debit) {
            foreach ($credits as $credit) {
                if (! $this->amountsEqual(abs((float) $debit->amount), (float) $credit->amount)) {
                    continue;
                }

                if (! $this->withinDateWindow($debit, $credit)) {
                    continue;
                }

                $gap = $this->postedAt($debit)->diffInDays($this->postedAt($credit), false);

                $candidates[] = [
                    'debit' => $debit,
                    'credit' => $credit,
                    'gap' => (int) $gap,
                    'abs_gap' => abs((int) $gap),
                ];
            }
        }

        usort($candidates, function (array $left, array $right): int {
            if ($left['abs_gap'] !== $right['abs_gap']) {
                return $left['abs_gap'] <=> $right['abs_gap'];
            }

            if ($left['debit']->id !== $right['debit']->id) {
                return $left['debit']->id <=> $right['debit']->id;
            }

            return $left['credit']->id <=> $right['credit']->id;
        });

        $usedDebitIds = [];
        $usedCreditIds = [];
        $pairs = [];

        foreach ($candidates as $candidate) {
            $debitId = $candidate['debit']->id;
            $creditId = $candidate['credit']->id;

            if (isset($usedDebitIds[$debitId]) || isset($usedCreditIds[$creditId])) {
                continue;
            }

            $tiedCredits = array_values(array_filter(
                $candidates,
                function (array $other) use ($candidate, $usedCreditIds): bool {
                    return $other['debit']->id === $candidate['debit']->id
                        && $other['abs_gap'] === $candidate['abs_gap']
                        && ! isset($usedCreditIds[$other['credit']->id]);
                },
            ));

            if (count($tiedCredits) !== 1) {
                continue;
            }

            $usedDebitIds[$debitId] = true;
            $usedCreditIds[$creditId] = true;

            $pairs[] = [
                'debit' => $candidate['debit'],
                'credit' => $candidate['credit'],
                'gap' => $candidate['gap'],
            ];
        }

        return $pairs;
    }

    protected function suggestPair(
        int $userId,
        BankTransaction $debit,
        BankTransaction $credit,
        float $confidence,
    ): void {
        TransactionTransferLink::query()->create([
            'user_id' => $userId,
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
            'transfer_group_id' => (string) Str::uuid(),
            'match_confidence' => $confidence,
            'status' => TransactionTransferLink::STATUS_SUGGESTED,
            'metadata' => [
                'source' => 'auto',
                'kind' => 'credit_card_payment',
            ],
        ]);
    }

    protected function confirmPair(
        int $userId,
        BankTransaction $debit,
        BankTransaction $credit,
        float $confidence,
    ): void {
        DB::transaction(function () use ($userId, $debit, $credit, $confidence): void {
            $groupId = (string) Str::uuid();

            TransactionTransferLink::query()->create([
                'user_id' => $userId,
                'debit_transaction_id' => $debit->id,
                'credit_transaction_id' => $credit->id,
                'transfer_group_id' => $groupId,
                'match_confidence' => $confidence,
                'status' => TransactionTransferLink::STATUS_CONFIRMED,
                'metadata' => [
                    'source' => 'auto',
                    'kind' => 'credit_card_payment',
                ],
            ]);

            foreach ([$debit, $credit] as $transaction) {
                $transaction->update([
                    'classification' => BankTransaction::CLASSIFICATION_TRANSFER,
                    'classification_source' => BankTransaction::CLASSIFICATION_SOURCE_PAIRED,
                    'classification_confidence' => $confidence,
                    'transfer_group_id' => $groupId,
                    'status' => 'ignored',
                ]);
            }
        });
    }

    protected function shouldAutoConfirm(int $gap): bool
    {
        return abs($gap) <= $this->dateWindowDays;
    }

    protected function confidenceForPair(
        BankTransaction $debit,
        BankTransaction $credit,
        int $gap,
    ): float {
        $absGap = abs($gap);

        if ($absGap === 0) {
            return 96.0;
        }

        if ($absGap === 1) {
            return 92.0;
        }

        if ($absGap === 2) {
            return 88.0;
        }

        return 84.0;
    }

    /**
     * @param  list<string>  $creditCardInstitutionTokens
     */
    protected function looksLikeCardPaymentDebit(
        BankTransaction $transaction,
        array $creditCardInstitutionTokens = [],
    ): bool {
        $description = $this->normalizedDescription($transaction);

        if ($description === '' || ! $this->containsPaymentToken($description)) {
            return false;
        }

        foreach ($this->cardIssuerTokens as $token) {
            if (str_contains($description, $token)) {
                return true;
            }
        }

        foreach ($creditCardInstitutionTokens as $token) {
            if ($token !== '' && str_contains($description, $token)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeCardPaymentCredit(BankTransaction $transaction): bool
    {
        $description = $this->normalizedDescription($transaction);

        if ($description === '' || ! $this->containsPaymentToken($description)) {
            return false;
        }

        foreach ($this->nonPaymentCreditTokens as $token) {
            if (str_contains($description, $token)) {
                return false;
            }
        }

        return true;
    }

    protected function containsPaymentToken(string $description): bool
    {
        foreach ($this->paymentTokens as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/', $description) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function withinDateWindow(BankTransaction $left, BankTransaction $right): bool
    {
        return abs($this->postedAt($left)->diffInDays($this->postedAt($right), false)) <= $this->dateWindowDays;
    }

    protected function postedAt(BankTransaction $transaction): Carbon
    {
        return Carbon::parse($transaction->posted_at)->startOfDay();
    }

    protected function normalizedDescription(BankTransaction $transaction): string
    {
        return $transaction->normalized_description
            ?? Str::of($transaction->description)->lower()->squish()->toString();
    }

    protected function amountsEqual(float $left, float $right): bool
    {
        return abs($left - $right) < 0.01;
    }
}
