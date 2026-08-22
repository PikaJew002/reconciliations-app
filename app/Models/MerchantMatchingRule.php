<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MerchantMatchingRule extends Model
{
    use HasFactory;

    public const MATCH_CONTAINS = 'contains';

    public const MATCH_EXTRACTED_NAME = 'extracted_name';

    /**
     * @return list<string>
     */
    public static function matchModes(): array
    {
        return [
            self::MATCH_CONTAINS,
            self::MATCH_EXTRACTED_NAME,
        ];
    }

    public static function normalizePattern(string $pattern): string
    {
        return Str::of($pattern)->lower()->squish()->toString();
    }

    protected $fillable = [
        'user_id',
        'merchant_id',
        'match_mode',
        'pattern',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
