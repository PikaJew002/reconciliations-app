<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Merchant extends Model
{
    use HasFactory;

    public const RETAILER = 'retailer';
    public const RESTAURANT = 'restaurant';
    public const SERVICE = 'service';
    public const UTILITY = 'utility';
    public const FINANCIAL = 'financial';
    public const GOVERNMENT = 'government';
    public const OTHER = 'other';

    protected $fillable = [
        'user_id',
        'name',
        'normalized_name',
        'website',
        'type',
        'supports_order_import',
        'supports_api',
        'metadata',
    ];

    protected $casts = [
        'supports_order_import' => 'boolean',
        'supports_api' => 'boolean',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function canImportOrders(): bool
    {
        return $this->supports_order_import;
    }

    public function hasApi(): bool
    {
        return $this->supports_api;
    }

    public function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url'],
            'type' => [
                'required',
                Rule::in([
                    self::RETAILER,
                    self::RESTAURANT,
                    self::SERVICE,
                    self::UTILITY,
                    self::FINANCIAL,
                    self::GOVERNMENT,
                    self::OTHER,
                ])
            ],
        ];
    }
}
