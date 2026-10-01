<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountCardAlias extends Model
{
    protected $fillable = [
        'account_id',
        'last_four',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
