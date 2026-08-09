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

    protected $fillable = [
        'user_id',
        'normalized_pattern',
        'classification',
        'direction',
        'origin',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
