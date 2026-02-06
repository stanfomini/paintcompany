<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory, SoftDeletes;

    public const STAGES = [
        'lead' => 'Lead',
        'quoted' => 'Quoted',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'paid' => 'Paid',
        'signed_accepted' => 'Signed & Accepted',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'customer_id',
        'property_id',
        'agent_id',
        'assigned_to',
        'created_by',
        'title',
        'description',
        'stage',
        'estimated_value',
        'actual_value',
        'expected_start_date',
        'expected_end_date',
        'actual_start_date',
        'actual_end_date',
        'lead_source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'expected_start_date' => 'date',
            'expected_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'sort_order' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the stage label.
     */
    public function getStageLabelAttribute(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    /**
     * Get the customer for this deal.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the property for this deal.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Get the referring agent for this deal.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Get the assigned team member for this deal.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who created this deal.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the notes for this deal.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(DealNote::class)->latest();
    }

    /**
     * Get the estimates for this deal.
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    /**
     * Get the activities for this deal.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Get the job for this deal.
     */
    public function job(): HasOne
    {
        return $this->hasOne(Job::class);
    }

    /**
     * Get the chat thread for this deal.
     */
    public function chatThread(): HasOne
    {
        return $this->hasOne(ChatThread::class);
    }

    /**
     * Get or create a chat thread for this deal.
     */
    public function getOrCreateChatThread(): ChatThread
    {
        return $this->chatThread ?? $this->chatThread()->create([
            'type' => ChatThread::TYPE_DEAL,
            'subject' => $this->title,
        ]);
    }

    /**
     * Move deal to a new stage.
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
     * Scope to filter by stage.
     */
    public function scopeByStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }

    /**
     * Scope to filter active deals (not cancelled or paid).
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('stage', ['cancelled', 'paid']);
    }

    /**
     * Scope to filter won deals (completed or paid).
     */
    public function scopeWon($query)
    {
        return $query->whereIn('stage', ['completed', 'paid']);
    }

    /**
     * Scope to filter deals for a specific agent's referrals.
     */
    public function scopeForAgent($query, User $agent)
    {
        return $query->where('agent_id', $agent->id);
    }

    /**
     * Scope to filter deals assigned to a user.
     */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where('assigned_to', $user->id);
    }

    /**
     * Scope to search deals.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('customer', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Check if deal is active (can be worked on).
     */
    public function isActive(): bool
    {
        return ! in_array($this->stage, ['cancelled', 'paid']);
    }

    /**
     * Check if deal is won.
     */
    public function isWon(): bool
    {
        return in_array($this->stage, ['completed', 'paid']);
    }

    /**
     * Check if deal is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->stage === 'cancelled';
    }
}
