<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceStepLog extends Model
{
    use HasFactory;

    protected $table = 'prospector_sequence_step_logs';

    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'step_id',
        'status',
        'result',
        'executed_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(SequenceEnrollment::class, 'enrollment_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'step_id');
    }
}
