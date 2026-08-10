<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReimbursementGroup extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'name',
        'notes',
        'status',
        'remainder_category_id',
        'remainder_classification',
        'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remainderCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'remainder_category_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ReimbursementGroupTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function expenseTotal(): float
    {
        return round((float) $this->legs
            ->where('role', ReimbursementGroupTransaction::ROLE_EXPENSE)
            ->sum('amount'), 2);
    }

    public function reimbursementTotal(): float
    {
        return round((float) $this->legs
            ->where('role', ReimbursementGroupTransaction::ROLE_REIMBURSEMENT)
            ->sum('amount'), 2);
    }

    public function net(): float
    {
        return round($this->expenseTotal() - $this->reimbursementTotal(), 2);
    }
}
