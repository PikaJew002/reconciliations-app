<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'plaid_item_id',
        'plaid_account_id',
        'persistent_account_id',
        'name',
        'official_name',
        'mask',
        'type',
        'subtype',
        'account_id',
    ];

    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
