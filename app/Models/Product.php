<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_id',
        'category_id',
        'name',
        'normalized_name',
        'sku',
        'brand',
        'upc',
        'size',
        'unit',
        'is_taxable',
        'category_confidence',
        'is_user_modified',
        'metadata',
    ];

    protected $casts = [
        'category_confidence' => 'decimal:2',
        'is_user_modified' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Null means the tax status has not been learned.
     * The boolean cast would turn that null into false.
     */
    protected function isTaxable(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?bool => $value === null ? null : (bool) $value,
            set: fn (mixed $value): ?int => $value === null ? null : ($value ? 1 : 0),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isCategorized(): bool
    {
        return ! is_null($this->category_id);
    }

    public function needsCategorization(): bool
    {
        return is_null($this->category_id);
    }

    public function validationRules(): array
    {
        return [
            'name' => ['required', 'string'],
            'normalized_name' => ['required', 'string'],
            'merchant_id' => ['required', 'exists:merchants,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'sku' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'upc' => ['nullable', 'string'],
        ];
    }
}
