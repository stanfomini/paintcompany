<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'type',
        'description',
        'amount',
        'is_percentage',
        'is_pretax',
        'is_active',
        'effective_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_percentage' => 'boolean',
            'is_pretax' => 'boolean',
            'is_active' => 'boolean',
            'effective_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePretax(Builder $query): Builder
    {
        return $query->where('is_pretax', true);
    }

    public function scopePosttax(Builder $query): Builder
    {
        return $query->where('is_pretax', false);
    }

    /**
     * Calculate the deduction amount for a given gross pay.
     */
    public function calculateAmount(float $grossPay): float
    {
        if ($this->is_percentage) {
            return round($grossPay * (float) $this->amount / 100, 2);
        }

        return round((float) $this->amount, 2);
    }
}
