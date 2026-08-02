<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'merchant_id',
        'merchant_description',
        'normalized_description',
        'sku',
        'upc',
        'match_confidence',
        'is_user_confirmed',
        'metadata',
    ];

    protected $casts = [
        'match_confidence' => 'decimal:2',
        'is_user_confirmed' => 'boolean',
        'metadata' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isConfirmed(): bool
    {
        return $this->is_user_confirmed;
    }

    public function needsReview(): bool
    {
        return !$this->is_user_confirmed;
    }

    public function validationRules(): array
    {
        return [
            'product_id' => ['required', 'exists:products,id'],
            'merchant_id' => ['required', 'exists:merchants,id'],
            'merchant_description' => ['required', 'string'],
            'normalized_description' => ['required', 'string'],
            'sku' => ['nullable', 'string'],
            'upc' => ['nullable', 'string'],
        ];
    }
}
