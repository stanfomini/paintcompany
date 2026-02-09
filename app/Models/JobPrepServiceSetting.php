<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPrepServiceSetting extends Model
{
    /** @use HasFactory<\Database\Factories\JobPrepServiceSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'job_prep_plan_id',
        'service_category_id',
        'crew_size',
        'hours_per_day',
        'scheduled_date',
        'total_labor_hours',
        'total_cleanup_hours',
        'total_days',
    ];

    protected function casts(): array
    {
        return [
            'crew_size' => 'integer',
            'hours_per_day' => 'float',
            'scheduled_date' => 'date',
            'total_labor_hours' => 'float',
            'total_cleanup_hours' => 'float',
            'total_days' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(JobPrepPlan::class, 'job_prep_plan_id');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function calculateTotalDays(): int
    {
        $totalHours = $this->total_labor_hours + $this->total_cleanup_hours;

        if ($this->crew_size <= 0 || $this->hours_per_day <= 0) {
            return 1;
        }

        return max(1, (int) ceil($totalHours / $this->crew_size / $this->hours_per_day));
    }
}
