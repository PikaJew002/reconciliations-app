<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TillerConnection extends Model
{
    protected $fillable = [
        'user_id',
        'callback_url',
        'webhook_secret',
    ];

    protected $casts = [
        'webhook_secret' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
