<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBuildRequest extends Model
{
    protected $fillable = [
        'estimate_id', 'status', 'areas_created', 'surfaces_created',
        'started_at', 'completed_at', 'log_path', 'error_message',
        'summary', 'callback_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'callback_sent_at' => 'datetime',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'building']);
    }

    public function markBuilding(): void
    {
        $this->update(['status' => 'building', 'started_at' => now()]);
    }

    public function markCompleted(int $areas, int $surfaces, string $summary): void
    {
        $this->update([
            'status' => 'completed',
            'areas_created' => $areas,
            'surfaces_created' => $surfaces,
            'summary' => $summary,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $status, string $message): void
    {
        $this->update([
            'status' => $status,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
