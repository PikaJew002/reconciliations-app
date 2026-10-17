<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidLinkEvent extends Model
{
    protected $fillable = [
        'user_id',
        'plaid_item_id',
        'link_session_id',
        'event',
        'institution_id',
        'error_type',
        'error_code',
        'request_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }
}
