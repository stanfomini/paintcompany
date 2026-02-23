<?php

namespace App\Models;

use App\Services\ChatService;
use App\Services\CommissionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Deal extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::updated(function (Deal $deal) {
            if ($deal->wasChanged('assigned_to') && $deal->assigned_to) {
                app(CommissionService::class)->createFromDeal($deal);
            }
        });
    }

    public const LEAD_SOURCES = [
        'referral' => 'Referral',
        'google_ads' => 'Google Ads',
        'home_advisor' => 'HomeAdvisor',
        'thumbtack' => 'Thumbtack',
        'yelp' => 'Yelp',
        'nextdoor' => 'Nextdoor',
        'facebook' => 'Facebook',
        'website' => 'Website',
        'yard_sign' => 'Yard Sign',
        'door_knock' => 'Door Knock',
        'repeat' => 'Repeat',
        'other' => 'Other',
    ];

    public const STAGES = [
        'lead' => 'New Lead',
        'not_contacted' => 'Not Contacted',
        'follow_up' => 'Follow Up',
        'quote_scheduled' => 'Quote Scheduled',
        'quoted' => 'Quoted',
        'signed' => 'Signed',
        'completed' => 'Completed',
        'lost' => 'Lost',
        'cancelled' => 'Cancelled',
    ];

    /** Stages shown on the kanban board (active work). */
    public const KANBAN_STAGES = ['lead', 'quote_scheduled', 'quoted'];

    /** Stages managed through the planner (follow-ups). */
    public const PLANNER_STAGES = ['not_contacted', 'follow_up'];

    /** Stages that represent terminal/completed states. */
    public const TERMINAL_STAGES = ['signed', 'completed', 'lost', 'cancelled'];

    /** Stages that represent past deals (done, lost, or cancelled — NOT signed, which is active work). */
    public const PAST_DEAL_STAGES = ['completed', 'lost', 'cancelled'];

    protected $fillable = [
        'customer_id',
        'property_id',
        'agent_id',
        'assigned_to',
        'lead_generator_id',
        'created_by',
        'lead_source_id',
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
        'origin_door_knock_id',
        'external_id',
        'follow_up_date',
        'sort_order',
        'listing_url',
        'listing_data',
        'listing_scraped_at',
        'agent_intent',
        'listing_value',
        'listing_bedrooms',
        'listing_sqft',
        'roi_scope',
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
            'follow_up_date' => 'date',
            'sort_order' => 'integer',
            'deleted_at' => 'datetime',
            'listing_data' => 'array',
            'listing_scraped_at' => 'datetime',
            'listing_value' => 'decimal:2',
            'listing_bedrooms' => 'integer',
            'listing_sqft' => 'integer',
        ];
    }

    /**
     * Get the stage label.
     */
    public function getStageLabelAttribute(): string
    {
        return __(self::STAGES[$this->stage] ?? $this->stage);
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
     * Get the lead generator (door knocker, referrer) for this deal.
     */
    public function leadGenerator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_generator_id');
    }

    /**
     * Get the user who created this deal.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the lead source for this deal.
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function originDoorKnock(): BelongsTo
    {
        return $this->belongsTo(DoorKnock::class, 'origin_door_knock_id');
    }

    /**
     * Get lead source options from the database.
     */
    public static function getLeadSourceOptions(): array
    {
        return LeadSource::getOptions();
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

    public function emailLinks(): MorphMany
    {
        return $this->morphMany(EmailCrmLink::class, 'linkable');
    }

    /**
     * Get the job for this deal.
     */
    public function job(): HasOne
    {
        return $this->hasOne(Job::class);
    }

    /**
     * Get the video calls for this deal (polymorphic).
     */
    public function videoCalls(): MorphMany
    {
        return $this->morphMany(VideoCall::class, 'callable');
    }

    /**
     * Get all ROI calculations for this deal.
     */
    public function roiCalculations(): HasMany
    {
        return $this->hasMany(RoiCalculation::class);
    }

    /**
     * Get the latest ROI calculation for this deal.
     */
    public function roiCalculation(): HasOne
    {
        return $this->hasOne(RoiCalculation::class)->latestOfMany();
    }

    /**
     * Get the collaborators for this deal.
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(DealCollaborator::class);
    }

    /**
     * Get active (not revoked, not expired) collaborators for this deal.
     */
    public function activeCollaborators(): HasMany
    {
        return $this->hasMany(DealCollaborator::class)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Check if a customer can access this deal (owner OR active collaborator).
     */
    public function customerCanAccess(Customer $customer): bool
    {
        if ($this->isOwnedBy($customer)) {
            return true;
        }

        return $this->activeCollaborators()
            ->where('customer_id', $customer->id)
            ->exists();
    }

    /**
     * Check if the customer is the owner of this deal.
     */
    public function isOwnedBy(Customer $customer): bool
    {
        return $this->customer_id === $customer->id;
    }

    /**
     * Scope to deals accessible by a customer (owned + active collaborations).
     */
    public function scopeAccessibleByCustomer($query, Customer $customer)
    {
        return $query->where(function ($q) use ($customer) {
            $q->where('customer_id', $customer->id)
                ->orWhereIn('id', DB::table('deal_collaborators')
                    ->where('customer_id', $customer->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->select('deal_id')
                );
        });
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
     * Automatically adds deal stakeholders (creator, customer, agent) as participants.
     */
    public function getOrCreateChatThread(): ChatThread
    {
        if ($this->chatThread) {
            return $this->chatThread;
        }

        $thread = $this->chatThread()->create([
            'type' => ChatThread::TYPE_DEAL,
            'subject' => $this->title,
            'last_message_at' => now(),
        ]);

        // Add deal stakeholders
        app(ChatService::class)->addDealStakeholders($thread, $this);

        return $thread;
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
        return $query->whereNotIn('stage', self::TERMINAL_STAGES);
    }

    /**
     * Scope to filter won deals (signed).
     */
    public function scopeWon($query)
    {
        return $query->where('stage', 'signed');
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
        return ! in_array($this->stage, self::TERMINAL_STAGES);
    }

    /**
     * Check if deal is won (signed).
     */
    public function isWon(): bool
    {
        return $this->stage === 'signed';
    }

    /**
     * Check if deal is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->stage, self::TERMINAL_STAGES);
    }

    /**
     * Check if deal is completed (work finished).
     */
    public function isCompleted(): bool
    {
        return $this->stage === 'completed';
    }

    /**
     * Check if deal is a past deal (completed, lost, or cancelled — NOT signed).
     */
    public function isPastDeal(): bool
    {
        return in_array($this->stage, self::PAST_DEAL_STAGES);
    }

    /**
     * Check if deal is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->stage === 'cancelled';
    }

    /**
     * Check if deal is lost.
     */
    public function isLost(): bool
    {
        return $this->stage === 'lost';
    }

    /**
     * Check if deal is on the kanban board.
     */
    public function isOnKanban(): bool
    {
        return in_array($this->stage, self::KANBAN_STAGES);
    }

    /**
     * Check if deal is a planner-managed follow-up.
     */
    public function isOnPlanner(): bool
    {
        return in_array($this->stage, self::PLANNER_STAGES);
    }

    /**
     * Scope to kanban-visible deals only.
     */
    public function scopeKanban($query)
    {
        return $query->whereIn('stage', self::KANBAN_STAGES);
    }

    /**
     * Scope to planner-managed deals only.
     */
    public function scopePlannerOnly($query)
    {
        return $query->whereIn('stage', self::PLANNER_STAGES);
    }
}
