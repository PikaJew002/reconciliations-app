<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'starts_on',
        'label',
        'color',
        'is_current',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'is_current' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function limits(): HasMany
    {
        return $this->hasMany(BudgetCategoryLimit::class);
    }

    public function startsOn(): CarbonInterface
    {
        return Carbon::parse($this->starts_on)->startOfMonth()->startOfDay();
    }

    public function endsOnExclusive(): CarbonInterface
    {
        return $this->startsOn()->copy()->addYear();
    }

    public function endsOnInclusive(): CarbonInterface
    {
        return $this->endsOnExclusive()->copy()->subDay();
    }

    public function containsMonth(CarbonInterface $month): bool
    {
        $start = $month->copy()->startOfMonth()->startOfDay();

        return $start->gte($this->startsOn()) && $start->lt($this->endsOnExclusive());
    }

    public static function labelForStart(CarbonInterface $startsOn): string
    {
        $start = $startsOn->copy()->startOfMonth();
        $end = $start->copy()->addYear()->subMonth();

        return $start->format('M Y').' – '.$end->format('M Y');
    }

    /**
     * @return array{id: int, label: string, color: string, starts_on: string, ends_on: string, is_current: bool}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'color' => $this->color,
            'starts_on' => $this->startsOn()->format('Y-m-d'),
            'ends_on' => $this->endsOnInclusive()->format('Y-m-d'),
            'is_current' => (bool) $this->is_current,
        ];
    }
}
