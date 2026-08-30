<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'scheduled_date',
        'expected_date',
        'expected_amount',
        'date_customized',
        'amount_customized',
        'lookback_days',
        'lookforward_days',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'scheduled_date' => 'date',
        'expected_date' => 'date',
        'date_customized' => 'boolean',
        'amount_customized' => 'boolean',
        'lookback_days' => 'integer',
        'lookforward_days' => 'integer',
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

    /**
     * The template-derived date this occurrence belongs to. Expected date can
     * move (bank holiday, early bill) without changing which month it is.
     */
    public function periodDate(): CarbonInterface
    {
        return ($this->scheduled_date ?? $this->expected_date)->copy()->startOfDay();
    }

    public function belongsToMonth(CarbonInterface $month): bool
    {
        return $this->periodDate()->isSameMonth($month);
    }

    public function scopeForPeriod(Builder $query, CarbonInterface $month): Builder
    {
        $start = $month->copy()->startOfMonth()->startOfDay();

        return $query
            ->where('scheduled_date', '>=', $start)
            ->where('scheduled_date', '<', $start->copy()->addMonth());
    }
}
