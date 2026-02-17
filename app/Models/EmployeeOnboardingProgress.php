<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeOnboardingProgress extends Model
{
    use HasFactory;

    protected $table = 'employee_onboarding_progress';

    protected $fillable = [
        'employee_profile_id',
        'onboarding_checklist_item_id',
        'employee_contract_id',
        'status',
        'completed_at',
        'completed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(OnboardingChecklistItem::class, 'onboarding_checklist_item_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function employeeContract(): BelongsTo
    {
        return $this->belongsTo(EmployeeContract::class);
    }
}
