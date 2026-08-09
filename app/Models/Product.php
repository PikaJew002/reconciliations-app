<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'normalized_name',
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
        'is_taxable' => 'boolean',
        'category_confidence' => 'decimal:2',
        'is_user_modified' => 'boolean',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function aliases()
    {
        return $this->hasMany(ProductAlias::class);
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
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand' => ['nullable', 'string'],
            'upc' => ['nullable', 'string'],
        ];
    }
}
