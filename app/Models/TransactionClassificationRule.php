<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionClassificationRule extends Model
{
    use HasFactory;

    public const CLASSIFICATION_INCOME = 'income';

    public const DIRECTION_CREDIT = 'credit';

    public const ORIGIN_USER_CONFIRMED = 'user_confirmed';

    public const ORIGIN_USER_REJECTED = 'user_rejected';

    public const MATCH_ONCE = 'once';

    public const MATCH_DESCRIPTION = 'description';

    public const MATCH_EXACT_DESCRIPTION_AND_AMOUNT = 'exact_description_and_amount';

    /**
     * @return list<string>
     */
    public static function persistableMatchModes(): array
    {
        return [
            self::MATCH_DESCRIPTION,
            self::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
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
        'normalized_pattern',
        'classification',
        'direction',
        'origin',
        'match_mode',
        'amount',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
