<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementGroupTransaction extends Model
{
    use HasFactory;

    public const ROLE_EXPENSE = 'expense';

    public const ROLE_REIMBURSEMENT = 'reimbursement';

    protected $fillable = [
        'reimbursement_group_id',
        'bank_transaction_id',
        'role',
        'amount',
        'prior_state',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'prior_state' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ReimbursementGroup::class, 'reimbursement_group_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function isExpense(): bool
    {
        return $this->role === self::ROLE_EXPENSE;
    }

    public function isReimbursement(): bool
    {
        return $this->role === self::ROLE_REIMBURSEMENT;
    }
}
