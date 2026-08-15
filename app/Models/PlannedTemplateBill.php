<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannedTemplateBill extends Model
{
    use HasFactory;

    protected $fillable = [
        'planned_template_id',
        'category_id',
        'expected_amount',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlannedTemplate::class, 'planned_template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
