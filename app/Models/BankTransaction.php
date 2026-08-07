<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'import_batch_id',
        'account_id',
        'merchant_id',
        'external_id',
        'posted_at',
        'transaction_date',
        'description',
        'normalized_description',
        'card_last_four',
        'amount',
        'currency',
        'status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'posted_at' => 'date',
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
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

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function allocations()
    {
        return $this->hasMany(TransactionAllocation::class);
    }

    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->allocations()->sum('allocated_amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        $amount = (float) $this->amount;
        $allocated = $this->allocated_amount;

        if ($amount < 0) {
            return -(abs($amount) - $allocated);
        }

        return $amount - $allocated;
    }

    public function getIsFullyAllocatedAttribute(): bool
    {
        return abs($this->remaining_amount) < 0.01;
    }

    public function markMatched(): void
    {
        $this->update([
            'status' => 'matched',
        ]);
    }

    public function markPartial(): void
    {
        $this->update([
            'status' => 'partial',
        ]);
    }

    public function markIgnored(): void
    {
        $this->update([
            'status' => 'ignored',
        ]);
    }

    public function validationRules(): array
    {
        return [
            'account_id' => ['required', 'exists:accounts,id'],
            'import_batch_id' => ['required', 'exists:import_batches,id'],
            'posted_at' => ['required', 'date'],
            'description' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'currency' => ['required', 'size:3'],
        ];
    }
}
