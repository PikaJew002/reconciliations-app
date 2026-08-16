<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class VenmoActivity extends Model
{
    use HasFactory;

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_WALLET_ONLY = 'wallet_only';

    public const TYPE_PAYMENT = 'payment';

    protected $fillable = [
        'user_id',
        'import_batch_id',
        'external_id',
        'occurred_at',
        'type',
        'status',
        'note',
        'from_name',
        'to_name',
        'amount',
        'fee',
        'funding_source',
        'destination',
        'funding_last_four',
        'destination_last_four',
        'bank_transaction_id',
        'cashed_out_by_activity_id',
        'match_status',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function cashedOutBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cashed_out_by_activity_id');
    }

    public function cashedOutPayments(): HasMany
    {
        return $this->hasMany(self::class, 'cashed_out_by_activity_id');
    }

    public function isPayment(): bool
    {
        return $this->type === self::TYPE_PAYMENT;
    }

    public function isIncomingPayment(): bool
    {
        return $this->isPayment() && (float) $this->amount > 0;
    }

    public function isDirectBankDebit(): bool
    {
        return $this->isPayment()
            && (float) $this->amount < 0
            && $this->funding_last_four !== null;
    }

    public function isTransferToBank(): bool
    {
        return str_contains($this->type, 'transfer')
            && (float) $this->amount < 0
            && $this->destination_last_four !== null;
    }

    public function isBankFacing(): bool
    {
        return $this->isDirectBankDebit() || $this->isTransferToBank();
    }

    public function isConfirmed(): bool
    {
        return $this->match_status === self::STATUS_CONFIRMED;
    }

    public function isSuggested(): bool
    {
        return $this->match_status === self::STATUS_SUGGESTED;
    }

    /**
     * @return list<int>
     */
    public function rejectedBankTransactionIds(): array
    {
        $ids = $this->metadata['rejected_bank_transaction_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    public function displayLabel(): string
    {
        $counterparty = $this->isIncomingPayment()
            ? $this->from_name
            : ($this->to_name ?: $this->from_name);

        $parts = array_values(array_filter([
            $counterparty !== null && trim($counterparty) !== '' ? trim($counterparty) : null,
            $this->note !== null && trim($this->note) !== '' ? trim($this->note) : null,
        ]));

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        if ($this->isTransferToBank()) {
            return 'Venmo cashout';
        }

        return ucfirst(str_replace('_', ' ', $this->type));
    }

    /**
     * @param  Collection<int, self>  $activities
     */
    public static function summarize(Collection $activities): ?string
    {
        $labels = $activities
            ->flatMap(function (self $activity): Collection {
                $cashedOut = $activity->relationLoaded('cashedOutPayments')
                    ? $activity->cashedOutPayments
                    : $activity->cashedOutPayments()->get();

                if ($cashedOut->isNotEmpty()) {
                    return $cashedOut->map(fn (self $payment): string => $payment->displayLabel());
                }

                return collect([$activity->displayLabel()]);
            })
            ->filter()
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return null;
        }

        return $labels->implode('; ');
    }
}
