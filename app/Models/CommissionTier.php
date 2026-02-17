<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'threshold_min',
        'threshold_max',
        'rate',
        'period_type',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'threshold_min' => 'decimal:2',
            'threshold_max' => 'decimal:2',
            'rate' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function scopeForEmployee(Builder $query, int $employeeProfileId): Builder
    {
        return $query->where('employee_profile_id', $employeeProfileId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
