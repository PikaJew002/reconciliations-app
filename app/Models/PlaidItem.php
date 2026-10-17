<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaidItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'item_id',
        'access_token',
        'institution_id',
        'institution_name',
        'cursor',
        'transactions_update_status',
        'link_session_id',
        'days_requested',
        'import_from',
        'error_code',
        'last_synced_at',
    ];

    protected $hidden = [
        'access_token',
        'cursor',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'days_requested' => 'integer',
            'import_from' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(PlaidAccount::class);
    }

    public function needsReconnect(): bool
    {
        return in_array($this->error_code, [
            'ITEM_LOGIN_REQUIRED',
            'PENDING_DISCONNECT',
            'PENDING_EXPIRATION',
        ], true);
    }
}
