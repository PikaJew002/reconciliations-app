<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_transaction_id',
        'order_component_id',
        'allocated_amount',
        'allocation_type',
        'match_confidence',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'match_confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function bankTransaction()
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function orderComponent()
    {
        return $this->belongsTo(OrderComponent::class);
    }

    public function isAutomatic(): bool
    {
        return $this->allocation_type === 'automatic';
    }

    public function isManual(): bool
    {
        return $this->allocation_type === 'manual';
    }

    public function isImported(): bool
    {
        return $this->allocation_type === 'imported';
    }

    public function validationRules(): array
    {
        return [
            'bank_transaction_id' => ['required', 'exists:bank_transactions,id'],
            'order_component_id' => ['required', 'exists:order_components,id'],
            'allocated_amount' => ['required', 'numeric'],
            'allocation_type' => ['required', 'in:automatic,manual,imported'],
            'match_confidence' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
