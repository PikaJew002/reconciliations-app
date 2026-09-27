<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'imported_total',
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
        'imported_total' => 'decimal:2',

        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if ($order->imported_total === null) {
                $order->imported_total = $order->total;
            }
        });
    }

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
        $net = DB::table('transaction_allocations')
            ->whereIn('order_component_id', $this->components()->select('id'))
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN allocation_type = ? THEN -allocated_amount ELSE allocated_amount END), 0) as net',
                [TransactionAllocation::TYPE_REFUND],
            )
            ->value('net');

        return round((float) $net, 2);
    }

    /**
     * Component amounts that must equal the bank total.
     * Bank refunds reduce the sum, including amounts above the component that cover tax.
     * Store-credit refunds stay in the sum because the card was not credited.
     */
    public function payableComponentSum(): float
    {
        $this->loadMissing('components');

        return round((float) $this->components->sum(
            fn (OrderComponent $component): float => $component->payableAmount(),
        ), 2);
    }

    public function bankRefundTotal(): float
    {
        $this->loadMissing('components');

        return round((float) $this->components->sum(
            function (OrderComponent $component): float {
                if ($component->refund_kind !== OrderComponent::REFUND_KIND_BANK) {
                    return 0.0;
                }

                return (float) $component->refund_amount;
            },
        ), 2);
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
