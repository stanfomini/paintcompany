<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'job_id',
        'date',
        'start_time',
        'end_time',
        'break_duration',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'break_duration' => 'integer',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: upcoming schedules (today and future).
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('date', '>=', now()->toDateString());
    }

    /**
     * Scope: schedules for a specific date.
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    /**
     * Scope: schedules for a specific employee.
     */
    public function scopeForEmployee(Builder $query, int $employeeProfileId): Builder
    {
        return $query->where('employee_profile_id', $employeeProfileId);
    }

    /**
     * Get scheduled hours (excluding break).
     */
    public function getScheduledHours(): float
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        $totalMinutes = $start->diffInMinutes($end);
        $workMinutes = $totalMinutes - $this->break_duration;

        return round($workMinutes / 60, 2);
    }

    /**
     * Check if this schedule is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    /**
     * Check if this schedule overlaps with another.
     */
    public function overlaps(Schedule $other): bool
    {
        if ($this->date->toDateString() !== $other->date->toDateString()) {
            return false;
        }

        if ($this->employee_profile_id !== $other->employee_profile_id) {
            return false;
        }

        return $this->start_time < $other->end_time && $this->end_time > $other->start_time;
    }
}
