<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $metadata = []): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], $metadata),
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
}
