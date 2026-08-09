<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'type',
        'description',
        'amount',
        'category_id',
        'category_confidence',
        'is_user_modified',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'category_confidence' => 'decimal:2',
        'is_user_modified' => 'boolean',
        'metadata' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function allocations()
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Computed Attributes
    |--------------------------------------------------------------------------
    */

    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->allocations()->sum('allocated_amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) (
            $this->amount -
            $this->allocated_amount
        );
    }

    public function getIsFullyAllocatedAttribute(): bool
    {
        return abs($this->remaining_amount) < 0.01;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isProduct(): bool
    {
        return $this->type === 'product';
    }

    public function isTax(): bool
    {
        return $this->type === 'tax';
    }

    public function isDelivery(): bool
    {
        return $this->type === 'delivery';
    }

    public function isDiscount(): bool
    {
        return $this->type === 'discount';
    }

    public function isTip(): bool
    {
        return $this->type === 'tip';
    }

    public function validationRules(): array
    {
        return [
            'order_id' => ['required', 'exists:orders,id'],
            'type' => ['required', 'string'],
            'description' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
        ];
    }
}
