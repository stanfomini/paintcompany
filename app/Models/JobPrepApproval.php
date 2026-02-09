<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPrepApproval extends Model
{
    /** @use HasFactory<\Database\Factories\JobPrepApprovalFactory> */
    use HasFactory;

    public const PARTY_TEAM = 'team';

    public const PARTY_CREW = 'crew';

    public const PARTY_CUSTOMER = 'customer';

    protected $fillable = [
        'job_prep_plan_id',
        'service_category_id',
        'party',
        'approved',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved' => 'boolean',
            'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approve(User $user): void
    {
        $this->update([
            'approved' => true,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
    }

    public function revoke(): void
    {
        $this->update([
            'approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }
}
