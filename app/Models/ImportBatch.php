<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source',
        'type',
        'original_filename',
        'storage_path',
        'record_count',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function venmoActivities(): HasMany
    {
        return $this->hasMany(VenmoActivity::class);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function markReverted(): void
    {
        $this->update([
            'status' => 'reverted',
        ]);
    }

    public function canRevert(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    /**
     * @param  array{min: ?string, max: ?string}|null  $dateRange
     * @return array<string, mixed>
     */
    public function historyPayload(?array $dateRange = null): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'type' => $this->type,
            'original_filename' => $this->original_filename,
            'record_count' => $this->record_count,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
            'can_revert' => $this->canRevert(),
            'date_range' => $dateRange,
        ];
    }

    public function validationRules(): array
    {
        return [
            'source' => ['required', 'string'],
            'type' => ['required', 'string'],
            'original_filename' => ['required', 'string'],
            'storage_path' => ['required', 'string'],
        ];
    }
}
