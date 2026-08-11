<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCategorizationRule extends Model
{
    use HasFactory;

    public const MATCH_EXACT_DESCRIPTION_AND_AMOUNT = 'exact_description_and_amount';

    public const MATCH_AMOUNT_AND_MERCHANT = 'amount_and_merchant';

    public const MATCH_MERCHANT = 'merchant';

    public const MATCH_DESCRIPTION = 'description';

    /** Bill-only: description starts with "CHECK " and amount matches. */
    public const MATCH_CHECK_AND_AMOUNT = 'check_and_amount';

    /** Bill-only: description starts with a user/suggested prefix and amount matches. */
    public const MATCH_DESCRIPTION_PREFIX_AND_AMOUNT = 'description_prefix_and_amount';

    public const MATCH_ONCE = 'once';

    public const CHECK_DESCRIPTION_PREFIX = 'check ';

    public const MIN_DESCRIPTION_PREFIX_LENGTH = 6;

    /**
     * @return list<string>
     */
    public static function persistableMatchModes(): array
    {
        return [
            self::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            self::MATCH_AMOUNT_AND_MERCHANT,
            self::MATCH_MERCHANT,
            self::MATCH_DESCRIPTION,
            self::MATCH_CHECK_AND_AMOUNT,
            self::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
        ];
    }

    /**
     * Match modes only valid when classifying as a bill.
     *
     * @return list<string>
     */
    public static function billOnlyMatchModes(): array
    {
        return [
            self::MATCH_CHECK_AND_AMOUNT,
            self::MATCH_DESCRIPTION_PREFIX_AND_AMOUNT,
        ];
    }

    /**
     * Persistable match modes for income credits.
     *
     * @return list<string>
     */
    public static function incomeMatchModes(): array
    {
        return [
            self::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            self::MATCH_DESCRIPTION,
        ];
    }

    /**
     * @return list<string>
     */
    public static function incomeAllMatchModes(): array
    {
        return [
            ...self::incomeMatchModes(),
            self::MATCH_ONCE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allMatchModes(): array
    {
        return [
            ...self::persistableMatchModes(),
            self::MATCH_ONCE,
        ];
    }

    protected $fillable = [
        'user_id',
        'category_id',
        'classification',
        'match_mode',
        'merchant_id',
        'normalized_pattern',
        'amount',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
}
