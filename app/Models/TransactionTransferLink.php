<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionTransferLink extends Model
{
    use HasFactory;

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'debit_transaction_id',
        'credit_transaction_id',
        'transfer_group_id',
        'match_confidence',
        'status',
        'metadata',
    ];

    protected $casts = [
        'match_confidence' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'debit_transaction_id');
    }

    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'credit_transaction_id');
    }

    public function isSuggested(): bool
    {
        return $this->status === self::STATUS_SUGGESTED;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }
}
