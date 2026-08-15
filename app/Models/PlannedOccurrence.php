<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlannedOccurrence extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'user_id',
        'template_id',
        'category_id',
        'merchant_id',
        'bank_transaction_id',
        'classification',
        'match_mode',
        'normalized_pattern',
        'amount',
        'expected_date',
        'expected_amount',
        'lookback_days',
        'lookforward_days',
        'status',
        'bills_customized',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'expected_date' => 'date',
        'lookback_days' => 'integer',
        'lookforward_days' => 'integer',
        'bills_customized' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlannedTemplate::class, 'template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PlannedOccurrenceBill::class);
    }

    public function paycheckAmount(): float
    {
        if ($this->isResolved() && $this->bankTransaction !== null) {
            return (float) $this->bankTransaction->amount;
        }

        return (float) $this->expected_amount;
    }

    public function assignedBillsTotal(): float
    {
        return round(
            (float) $this->bills->sum(fn (PlannedOccurrenceBill $bill): float => (float) $bill->expected_amount),
            2,
        );
    }

    public function leftoverForExpenses(): float
    {
        return round($this->paycheckAmount() - $this->assignedBillsTotal(), 2);
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function windowStart(): CarbonInterface
    {
        return Carbon::parse($this->expected_date)
            ->startOfDay()
            ->subDays($this->lookback_days);
    }

    public function windowEnd(): CarbonInterface
    {
        return Carbon::parse($this->expected_date)
            ->startOfDay()
            ->addDays($this->lookforward_days);
    }

    public static function expectedDateForMonth(CarbonInterface $month, int $expectedDay): CarbonInterface
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $day = min(max($expectedDay, 1), $start->daysInMonth);

        return $start->copy()->day($day);
    }
}
