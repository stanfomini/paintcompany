<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'job_id',
        'clock_in',
        'clock_out',
        'clock_in_lat',
        'clock_in_lng',
        'clock_out_lat',
        'clock_out_lng',
        'clock_in_address',
        'clock_out_address',
        'clock_in_geofence_status',
        'clock_out_geofence_status',
        'geofence_distance_ft',
        'break_start',
        'break_end',
        'break_minutes',
        'total_hours',
        'overtime_hours',
        'status',
        'dispute_reason',
        'approved_by',
        'approved_at',
        'edited_by',
        'edited_at',
        'original_clock_in',
        'original_clock_out',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'break_start' => 'datetime',
            'break_end' => 'datetime',
            'approved_at' => 'datetime',
            'edited_at' => 'datetime',
            'original_clock_in' => 'datetime',
            'original_clock_out' => 'datetime',
            'total_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'clock_in_lat' => 'decimal:7',
            'clock_in_lng' => 'decimal:7',
            'clock_out_lat' => 'decimal:7',
            'clock_out_lng' => 'decimal:7',
            'geofence_distance_ft' => 'integer',
            'break_minutes' => 'integer',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * Check if the entry is currently active (clocked in, not out).
     */
    public function isActive(): bool
    {
        return $this->clock_in !== null && $this->clock_out === null;
    }

    /**
     * Check if the employee is currently on break.
     */
    public function isOnBreak(): bool
    {
        return $this->break_start !== null && $this->break_end === null;
    }

    /**
     * Calculate total hours worked (clock_out - clock_in - break_minutes).
     */
    public function calculateTotalHours(): float
    {
        if (! $this->clock_in || ! $this->clock_out) {
            return 0.0;
        }

        $totalMinutes = $this->clock_in->diffInMinutes($this->clock_out);
        $workedMinutes = $totalMinutes - ($this->break_minutes ?? 0);

        return round(max(0, $workedMinutes) / 60, 2);
    }

    /**
     * Clock out the entry.
     */
    public function clockOut(?float $lat = null, ?float $lng = null): void
    {
        $this->update([
            'clock_out' => now(),
            'clock_out_lat' => $lat,
            'clock_out_lng' => $lng,
            'total_hours' => $this->calculateTotalHoursAt(now()),
        ]);
    }

    /**
     * Start a break.
     */
    public function startBreak(): void
    {
        $this->update([
            'break_start' => now(),
        ]);
    }

    /**
     * End a break and accumulate break minutes.
     */
    public function endBreak(): void
    {
        if (! $this->break_start) {
            return;
        }

        $breakMinutes = $this->break_start->diffInMinutes(now());

        $this->update([
            'break_end' => now(),
            'break_minutes' => ($this->break_minutes ?? 0) + $breakMinutes,
        ]);
    }

    /**
     * Calculate total hours if clocking out at a given time.
     */
    private function calculateTotalHoursAt(\DateTimeInterface $clockOut): float
    {
        if (! $this->clock_in) {
            return 0.0;
        }

        $totalMinutes = $this->clock_in->diffInMinutes($clockOut);
        $workedMinutes = $totalMinutes - ($this->break_minutes ?? 0);

        return round(max(0, $workedMinutes) / 60, 2);
    }
}
