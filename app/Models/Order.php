<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'import_batch_id',
        'merchant_id',
        'order_number',
        'ordered_at',
        'fulfilled_at',
        'delivered_at',
        'subtotal',
        'tax',
        'delivery_fee',
        'tip',
        'discount',
        'total',
        'currency',
        'payment_last_four',
        'shipping_state',
        'shipping_zip',
        'status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'ordered_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'delivered_at' => 'datetime',

        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'tip' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',

        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function components()
    {
        return $this->hasMany(OrderComponent::class);
    }

    public function getProductSubtotalAttribute(): float
    {
        return (float) $this->items()->sum('extended_price');
    }

    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->components()
            ->withSum('allocations', 'allocated_amount')
            ->get()
            ->sum('allocations_sum_allocated_amount');
    }

    public function getIsFullyAllocatedAttribute(): bool
    {
        return abs($this->allocated_amount - $this->total) < 0.01;
    }

    public function markReconciled(): void
    {
        $this->update([
            'status' => 'reconciled',
        ]);
    }

    public function validationRules(): array
    {
        return [
            'merchant_id' => ['required', 'exists:merchants,id'],
            'import_batch_id' => ['required', 'exists:import_batches,id'],
            'order_number' => ['required', 'string'],
            'subtotal' => ['required', 'numeric'],
            'total' => ['required', 'numeric'],
            'currency' => ['required', 'size:3'],
        ];
    }
}
