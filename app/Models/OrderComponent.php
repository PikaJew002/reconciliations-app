<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderComponent extends Model
{
    use HasFactory;

    public const REFUND_KIND_BANK = 'bank';

    public const REFUND_KIND_OFF_BOOK = 'off_book';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'type',
        'description',
        'amount',
        'refund_amount',
        'refund_kind',
        'category_id',
        'category_confidence',
        'is_user_modified',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
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
        $query = $this->relationLoaded('allocations')
            ? $this->allocations->where('allocation_type', '!=', TransactionAllocation::TYPE_REFUND)
            : $this->allocations()->where('allocation_type', '!=', TransactionAllocation::TYPE_REFUND);

        return round((float) $query->sum('allocated_amount'), 2);
    }

    public function getRemainingAmountAttribute(): float
    {
        return round((float) $this->amount - $this->allocated_amount, 2);
    }

    /**
     * Amount counted toward the bank total. Bank refunds subtract in full,
     * so a refund larger than this line can offset order-level tax.
     */
    public function payableAmount(): float
    {
        $amount = (float) $this->amount;

        if ($this->refund_kind === self::REFUND_KIND_BANK) {
            return round($amount - (float) $this->refund_amount, 2);
        }

        return round($amount, 2);
    }

    /**
     * Amount counted as spending. Both refund kinds subtract in full.
     */
    public function spendAmount(): float
    {
        return round((float) $this->amount - (float) ($this->refund_amount ?? 0), 2);
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
