<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;

class Account extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const CHECKING = 'checking';

    public const SAVINGS = 'savings';

    public const CREDIT_CARD = 'credit_card';

    public const CASH = 'cash';

    public const OFF_BOOK = 'off_book';

    public const OFF_BOOK_EXTERNAL_ID = 'system:off-book';

    public const OFF_BOOK_NAME = 'Off-book';

    protected $fillable = [
        'user_id',
        'name', // user friendly name for the account
        'institution_name',
        'account_name',
        'account_type',
        'default_classification',
        'currency',
        'last_four',
        'external_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bankTransactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function pendingSpends()
    {
        return $this->hasMany(PendingSpend::class);
    }

    public function isOffBook(): bool
    {
        return $this->external_id === self::OFF_BOOK_EXTERNAL_ID;
    }

    public function scopeTracked($query)
    {
        return $query->where(function ($builder): void {
            $builder
                ->whereNull('external_id')
                ->orWhere('external_id', '!=', self::OFF_BOOK_EXTERNAL_ID);
        });
    }

    public function scopeOffBook($query)
    {
        return $query->where('external_id', self::OFF_BOOK_EXTERNAL_ID);
    }

    public function validationRules(): array
    {
        return [

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'institution_name' => [
                'required',
                'string',
                'max:255',
            ],

            'account_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'account_type' => [
                'required',
                Rule::in([
                    self::CHECKING,
                    self::SAVINGS,
                    self::CREDIT_CARD,
                    self::CASH,
                ]),
            ],

            'default_classification' => [
                'required',
                Rule::in([
                    BankTransaction::CLASSIFICATION_BILL,
                    BankTransaction::CLASSIFICATION_EXPENSE,
                ]),
            ],

            'currency' => [
                'required',
                'size:3',
            ],

            'last_four' => [
                'nullable',
                'digits:4',
            ],

        ];
    }
}
