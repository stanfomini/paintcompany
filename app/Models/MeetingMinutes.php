<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingMinutes extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporation_id',
        'recorded_by',
        'title',
        'meeting_date',
        'meeting_type',
        'audio_path',
        'audio_duration',
        'transcript',
        'transcript_segments',
        'executive_summary',
        'summary_status',
        'attendees',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'meeting_date' => 'date',
            'transcript_segments' => 'array',
            'attendees' => 'array',
        ];
    }

    public const MEETING_TYPES = [
        'annual' => 'Annual Meeting',
        'special' => 'Special Meeting',
        'board' => 'Board Meeting',
        'emergency' => 'Emergency Meeting',
    ];

    public const SUMMARY_STATUSES = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
