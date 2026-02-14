<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlaMetric extends Model
{
    /** @use HasFactory<\Database\Factories\SlaMetricFactory> */
    use HasFactory;

    public const METRIC_TYPES = [
        'speed_to_lead' => 'Speed to Lead (minutes)',
        'follow_up_compliance' => 'Follow-Up Compliance (%)',
        'response_time' => 'Response Time (minutes)',
        'estimate_turnaround' => 'Estimate Turnaround (hours)',
    ];

    public const PERIODS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];

    protected $fillable = [
        'user_id',
        'metric_type',
        'period',
        'period_date',
        'value',
        'sample_count',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'value' => 'decimal:2',
            'sample_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('metric_type', $type);
    }

    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }
}
