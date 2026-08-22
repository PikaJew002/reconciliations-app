<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingSpend extends Model
{
    use HasFactory;

    public const SOURCE_DEBIT_CARD = 'debit_card';

    public const SOURCE_CREDIT_CARD = 'credit_card';

    public const SOURCE_VENMO = 'venmo';

    public const STATUS_PENDING = 'pending';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELLED = 'cancelled';

    public const REVIEW_NOT_FOUND = 'not_found';

    public const REVIEW_AMBIGUOUS = 'ambiguous';

    public const LOOKBACK_DAYS = 1;

    public const LOOKFORWARD_DAYS = 7;

    protected $fillable = [
        'user_id',
        'account_id',
        'merchant_id',
        'category_id',
        'source',
        'spent_at',
        'amount',
        'card_last_four',
        'classification',
        'status',
        'review_reason',
        'bank_transaction_id',
        'venmo_activity_id',
        'notes',
    ];

    protected $casts = [
        'spent_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    /**
     * @return list<string>
     */
    public static function sources(): array
    {
        return [
            self::SOURCE_DEBIT_CARD,
            self::SOURCE_CREDIT_CARD,
            self::SOURCE_VENMO,
        ];
    }

    /**
     * @return list<string>
     */
    public static function unmatchedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_NEEDS_REVIEW,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function venmoActivity(): BelongsTo
    {
        return $this->belongsTo(VenmoActivity::class);
    }

    public function isVenmo(): bool
    {
        return $this->source === self::SOURCE_VENMO;
    }

    public function isUnmatched(): bool
    {
        return in_array($this->status, self::unmatchedStatuses(), true);
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function windowStart(): CarbonInterface
    {
        return Carbon::parse($this->spent_at)->startOfDay()->subDays(self::LOOKBACK_DAYS);
    }

    public function windowEnd(): CarbonInterface
    {
        return Carbon::parse($this->spent_at)->startOfDay()->addDays(self::LOOKFORWARD_DAYS);
    }

    public function spentOn(): CarbonInterface
    {
        return Carbon::parse($this->spent_at)->startOfDay();
    }
}
