<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Job extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'painting_jobs';

    public const STAGES = [
        'estimate_accepted' => 'Estimate Accepted',
        'scheduling_colors' => 'Scheduling & Colors',
        'job_prep' => 'Job Prep',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
    ];

    public const STAGE_ORDER = [
        'estimate_accepted' => 1,
        'scheduling_colors' => 2,
        'job_prep' => 3,
        'in_progress' => 4,
        'completed' => 5,
    ];

    protected $fillable = [
        'deal_id',
        'estimate_id',
        'job_number',
        'stage',
        'title',
        'description',
        'scheduled_date',
        'actual_start_date',
        'actual_end_date',
        'allowed_colors',
        'required_crew_size',
        'company_date_confirmed',
        'customer_date_confirmed',
        'company_colors_confirmed',
        'customer_colors_confirmed',
        'dispatched_at',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'allowed_colors' => 'integer',
            'required_crew_size' => 'integer',
            'company_date_confirmed' => 'boolean',
            'customer_date_confirmed' => 'boolean',
            'company_colors_confirmed' => 'boolean',
            'customer_colors_confirmed' => 'boolean',
            'dispatched_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function colors(): HasMany
    {
        return $this->hasMany(JobColor::class)->orderBy('sort_order');
    }

    public function crews(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'job_crew', 'job_id', 'crew_user_id')
            ->withPivot('status', 'dispatched_at', 'responded_at', 'notes', 'service_category_id')
            ->withTimestamps();
    }

    public function serviceRequirements(): HasMany
    {
        return $this->hasMany(JobServiceRequirement::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function costCards(): HasMany
    {
        return $this->hasMany(JobCostCard::class);
    }

    public function prepPlan(): HasOne
    {
        return $this->hasOne(JobPrepPlan::class);
    }

    /**
     * Check if the prep plan is fully approved by all three parties.
     */
    public function isPrepApproved(): bool
    {
        return $this->prepPlan?->isFullyApproved() ?? false;
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function jobActivities(): HasMany
    {
        return $this->hasMany(JobActivity::class);
    }

    public function videoCalls(): HasMany
    {
        return $this->hasMany(VideoCall::class);
    }

    public function videoRecordings(): MorphMany
    {
        return $this->morphMany(VideoRecording::class, 'recordable');
    }

    /**
     * Get the stage label.
     */
    public function getStageLabelAttribute(): string
    {
        return __(self::STAGES[$this->stage] ?? $this->stage);
    }

    /**
     * Get the stage number (1-5).
     */
    public function getStageNumberAttribute(): int
    {
        return self::STAGE_ORDER[$this->stage] ?? 0;
    }

    /**
     * Move to a new stage.
     */
    public function moveTo(string $stage): bool
    {
        if (! array_key_exists($stage, self::STAGES)) {
            return false;
        }

        $this->stage = $stage;

        return $this->save();
    }

    /**
     * Advance to the next stage in order.
     */
    public function advanceStage(): bool
    {
        $stages = array_keys(self::STAGES);
        $currentIndex = array_search($this->stage, $stages);

        if ($currentIndex === false || $currentIndex >= count($stages) - 1) {
            return false;
        }

        return $this->moveTo($stages[$currentIndex + 1]);
    }

    /**
     * Check if scheduling and colors are fully confirmed.
     */
    public function isReadyForDispatch(): bool
    {
        return $this->company_date_confirmed
            && $this->customer_date_confirmed
            && $this->company_colors_confirmed
            && $this->customer_colors_confirmed;
    }

    /**
     * Check if the job is active.
     */
    public function isActive(): bool
    {
        return $this->stage !== 'completed';
    }

    /**
     * Check if the job is completed.
     */
    public function isCompleted(): bool
    {
        return $this->stage === 'completed';
    }

    /**
     * Get the customer via the deal.
     */
    public function getCustomer(): ?Customer
    {
        return $this->deal?->customer;
    }

    /**
     * Get the property via the deal.
     */
    public function getProperty(): ?Property
    {
        return $this->deal?->property;
    }

    /**
     * Generate a unique job number.
     */
    public static function generateJobNumber(): string
    {
        $year = now()->format('Y');

        // MySQL uses SIGNED, SQLite uses INTEGER for CAST
        $castType = DB::connection()->getDriverName() === 'mysql' ? 'SIGNED' : 'INTEGER';

        $latest = static::withTrashed()
            ->where('job_number', 'like', "JOB-{$year}-%")
            ->orderByRaw("CAST(SUBSTR(job_number, -5) AS {$castType}) DESC")
            ->first();

        $nextNumber = $latest
            ? (int) substr($latest->job_number, -5) + 1
            : 1;

        return sprintf('JOB-%s-%05d', $year, $nextNumber);
    }

    /**
     * Get the accepted crew (if any).
     */
    public function acceptedCrew(): BelongsToMany
    {
        return $this->crews()->wherePivot('status', 'accepted');
    }

    /**
     * Scope: filter by stage.
     */
    public function scopeByStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }

    /**
     * Scope: only active (not completed) jobs.
     */
    public function scopeActive($query)
    {
        return $query->where('stage', '!=', 'completed');
    }

    /**
     * Scope: jobs dispatched to a specific crew.
     */
    public function scopeForCrew($query, User $crew)
    {
        return $query->whereHas('crews', function ($q) use ($crew) {
            $q->where('crew_user_id', $crew->id);
        });
    }
}
