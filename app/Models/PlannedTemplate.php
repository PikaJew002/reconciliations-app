<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlannedTemplate extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = true;

    /**
     * @return list<string>
     */
    public static function incomeMatchModes(): array
    {
        return [
            TransactionCategorizationRule::MATCH_DESCRIPTION,
            TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            TransactionCategorizationRule::MATCH_MERCHANT,
            TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function billMatchModes(): array
    {
        return [
            TransactionCategorizationRule::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
            TransactionCategorizationRule::MATCH_CHECK_AND_AMOUNT,
            TransactionCategorizationRule::MATCH_DESCRIPTION,
            TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            TransactionCategorizationRule::MATCH_MERCHANT,
            TransactionCategorizationRule::MATCH_AMOUNT_AND_MERCHANT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function matchModesForKind(string $kind): array
    {
        return $kind === Category::KIND_BILL
            ? self::billMatchModes()
            : self::incomeMatchModes();
    }

    protected $fillable = [
        'user_id',
        'category_id',
        'merchant_id',
        'name',
        'classification',
        'match_mode',
        'normalized_pattern',
        'amount',
        'expected_day',
        'expected_amount',
        'lookback_days',
        'lookforward_days',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'expected_day' => 'integer',
        'lookback_days' => 'integer',
        'lookforward_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PlannedOccurrence::class, 'template_id');
    }

    public function assignedBills(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'planned_template_assignments',
            'paycheck_template_id',
            'bill_template_id',
        )->withTimestamps();
    }

    public function assignedPaycheck(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'planned_template_assignments',
            'bill_template_id',
            'paycheck_template_id',
        )->withTimestamps();
    }

    /**
     * @return array<string, mixed>
     */
    public function matchAttributes(): array
    {
        return [
            'classification' => $this->classification,
            'category_id' => $this->category_id,
            'merchant_id' => $this->merchant_id,
            'match_mode' => $this->match_mode,
            'normalized_pattern' => $this->normalized_pattern,
            'amount' => $this->amount,
            'expected_amount' => $this->expected_amount,
            'lookback_days' => $this->lookback_days,
            'lookforward_days' => $this->lookforward_days,
        ];
    }
}
