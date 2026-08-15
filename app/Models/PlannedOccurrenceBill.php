<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannedOccurrenceBill extends Model
{
    use HasFactory;

    protected $fillable = [
        'planned_occurrence_id',
        'category_id',
        'source_template_bill_id',
        'expected_amount',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
    ];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sourceTemplateBill(): BelongsTo
    {
        return $this->belongsTo(PlannedTemplateBill::class, 'source_template_bill_id');
    }
}
