<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'line_number',
        'sku',
        'upc',
        'description',
        'normalized_description',
        'quantity',
        'unit_price',
        'extended_price',
        'taxable',
        'match_confidence',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'extended_price' => 'decimal:2',
        'taxable' => 'boolean',
        'match_confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function components()
    {
        return $this->hasMany(OrderComponent::class);
    }

    public function getTaxComponentAttribute()
    {
        return $this->components()
            ->where('type', 'tax')
            ->first();
    }

    public function getTotalCostAttribute(): float
    {
        return (float) (
            $this->extended_price +
            ($this->tax_component?->amount ?? 0)
        );
    }

    public function isMatched(): bool
    {
        return !is_null($this->product_id);
    }

    public function needsProductMatch(): bool
    {
        return is_null($this->product_id);
    }

    public function validationRules(): array
    {
        return [
            'order_id' => ['required', 'exists:orders,id'],
            'description' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_price' => ['required', 'numeric'],
            'extended_price' => ['required', 'numeric'],
        ];
    }
}
