<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ComponentTaxRule extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_id',
        'type',
        'normalized_description',
        'is_taxable',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
    ];

    public static function normalize(string $description): string
    {
        return Str::of($description)->lower()->squish()->toString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
