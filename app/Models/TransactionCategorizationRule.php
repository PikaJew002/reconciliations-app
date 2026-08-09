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

    public const MATCH_ONCE = 'once';

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
