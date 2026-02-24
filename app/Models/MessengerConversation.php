<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessengerConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_id',
        'thread_id',
        'facebook_psid',
        'current_step_id',
        'status',
        'answers',
        'customer_id',
        'deal_id',
        'started_at',
        'completed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(MessengerBotFlow::class, 'flow_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(MessengerBotStep::class, 'current_step_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeTimedOut($query, ?int $minutes = null)
    {
        $timeout = $minutes ?? 30;

        return $query->where('status', 'in_progress')
            ->where('last_activity_at', '<', now()->subMinutes($timeout));
    }

    /**
     * Extract mapped answers as an associative array of field => value.
     */
    public function getCollectedData(): array
    {
        $answers = $this->answers ?? [];
        $mappedData = [];

        $flow = $this->flow()->with('steps')->first();
        if (! $flow) {
            return $mappedData;
        }

        foreach ($flow->steps as $step) {
            if ($step->field_mapping && isset($answers["step_{$step->id}"])) {
                $mappedData[$step->field_mapping] = $answers["step_{$step->id}"];
            }
        }

        return $mappedData;
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function isAbandoned(): bool
    {
        return $this->status === 'abandoned';
    }

    public function isHumanTakeover(): bool
    {
        return $this->status === 'human_takeover';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }
}
