<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallIntelligence extends Model
{
    use HasFactory;

    protected $table = 'prospector_call_intelligence';

    protected $fillable = [
        'call_id',
        'transcription_raw',
        'transcription_segments',
        'speaker_labels',
        'sentiment_overall',
        'sentiment_by_segment',
        'lead_score',
        'scoring_factors',
        'keywords',
        'objections',
        'action_items',
        'ai_summary',
        'ai_coaching_notes',
        'model_version',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'transcription_segments' => 'array',
            'speaker_labels' => 'array',
            'sentiment_overall' => 'decimal:2',
            'sentiment_by_segment' => 'array',
            'lead_score' => 'integer',
            'scoring_factors' => 'array',
            'keywords' => 'array',
            'objections' => 'array',
            'action_items' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if AI processing has been completed.
     */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Get a human-readable score label.
     */
    public function getScoreLabel(): string
    {
        if ($this->lead_score === null) {
            return 'unknown';
        }

        return match (true) {
            $this->lead_score <= 30 => 'cold',
            $this->lead_score <= 60 => 'warm',
            default => 'hot',
        };
    }

    /**
     * Check if transcription is available.
     */
    public function hasTranscription(): bool
    {
        return $this->transcription_raw !== null && $this->transcription_raw !== '';
    }
}
