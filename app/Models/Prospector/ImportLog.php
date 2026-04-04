<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'prospector_import_logs';

    protected $fillable = [
        'type',
        'status',
        'total_records',
        'processed',
        'created',
        'updated',
        'errors',
        'error_details',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'error_details' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function markRunning(int $total): void
    {
        $this->update([
            'status' => 'running',
            'total_records' => $total,
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

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_details' => array_merge($this->error_details ?? [], [['message' => $reason]]),
        ]);
    }

    public function incrementProcessed(): void
    {
        $this->increment('processed');
    }

    public function incrementCreated(): void
    {
        $this->increment('created');
    }

    public function incrementUpdated(): void
    {
        $this->increment('updated');
    }

    public function incrementErrors(array $detail = []): void
    {
        $this->increment('errors');
        if ($detail) {
            $this->update([
                'error_details' => array_merge($this->error_details ?? [], [$detail]),
            ]);
        }
    }
}
