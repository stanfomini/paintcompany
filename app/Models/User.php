<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar_path',
        'password',
        // 'type' and 'is_active' intentionally excluded - set explicitly in code to prevent privilege escalation
        'needs_onboarding',
        'calendar_token',
        'referral_code',
        'referral_payment_method',
        'referral_payment_handle',
        'referral_terms_accepted_at',
        'referral_terms_ip_address',
        'referral_terms_initials',
        'follow_up_status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'needs_onboarding' => 'boolean',
            'referral_terms_accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            match ($user->type) {
                'internal' => $user->internalUser()->create(),
                'agent' => $user->agentProfile()->create(),
                'customer' => $user->customerProfile()->create(),
                'crew' => $user->crewProfile()->create(),
                default => null,
            };

            // Fire automation event for workflow triggers
            event(new \App\Events\Automation\ContactCreated($user, $user->type ?? ''));
        });
    }

    public function internalUser(): HasOne
    {
        return $this->hasOne(InternalUser::class);
    }

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function crewProfile(): HasOne
    {
        return $this->hasOne(CrewProfile::class);
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    /**
     * Deals assigned to this user as salesperson.
     */
    public function assignedDeals(): HasMany
    {
        return $this->hasMany(Deal::class, 'assigned_to');
    }

    /**
     * Get the signed contracts for this crew member.
     */
    public function crewSignedContracts(): HasMany
    {
        return $this->hasMany(CrewSignedContract::class);
    }

    /**
     * Get the W9 form for this crew member.
     */
    public function crewW9Form(): HasOne
    {
        return $this->hasOne(CrewW9Form::class);
    }

    /**
     * Get territory offers claimed by this user.
     */
    public function claimedTerritoryOffers(): HasMany
    {
        return $this->hasMany(TerritoryOffer::class, 'claimed_by');
    }

    /**
     * Get the door knocks recorded by this user.
     */
    public function doorKnocks(): HasMany
    {
        return $this->hasMany(DoorKnock::class);
    }

    /**
     * Get the jobs assigned to this crew member.
     */
    public function crewJobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_crew', 'crew_user_id', 'job_id')
            ->withPivot('status', 'dispatched_at', 'responded_at', 'notes')
            ->withTimestamps();
    }

    /**
     * Get the properties managed by this agent.
     */
    public function agentProperties(): HasMany
    {
        return $this->hasMany(Property::class, 'agent_id');
    }

    /**
     * Get the customers referred by this agent.
     */
    public function referredCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by');
    }

    /**
     * Get the referrals made by this user.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    /**
     * Get or create a unique referral code for this user.
     */
    public function getOrCreateReferralCode(): string
    {
        if (! $this->referral_code) {
            do {
                $code = strtoupper(Str::random(8));
            } while (static::where('referral_code', $code)->exists());

            $this->update(['referral_code' => $code]);
        }

        return $this->referral_code;
    }

    /**
     * Get the full referral link for this user.
     */
    public function referralLink(): string
    {
        return url('/?ref='.$this->getOrCreateReferralCode());
    }

    public function isInternal(): bool
    {
        return $this->type === 'internal';
    }

    public function isAgent(): bool
    {
        return $this->type === 'agent';
    }

    public function isCustomer(): bool
    {
        return $this->type === 'customer';
    }

    public function isCrew(): bool
    {
        return $this->type === 'crew';
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function chatParticipants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    /**
     * Determine if user can access the internal dashboard.
     * Requires internal type + appropriate role/permission.
     */
    public function canAccessInternalDashboard(): bool
    {
        return $this->isInternal() && $this->hasAnyRole(['owner', 'admin', 'manager', 'estimator']);
    }

    /**
     * Determine if user can view roadmap.
     */
    public function canViewRoadmap(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('roadmap.view');
    }

    /**
     * Determine if user can edit roadmap.
     */
    public function canEditRoadmap(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('roadmap.edit');
    }

    /**
     * Determine if user can view KPIs.
     */
    public function canViewKpis(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('kpis.view');
    }

    /**
     * Determine if user can view sensitive financial data.
     */
    public function canViewFinancials(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('financials.view_cost_breakdown');
    }

    /**
     * Determine if user is the business owner.
     */
    public function isOwner(): bool
    {
        return $this->isInternal() && $this->hasRole('owner');
    }

    /**
     * Get or create a calendar token for iCal feed subscriptions.
     */
    public function getOrCreateCalendarToken(): string
    {
        if (! $this->calendar_token) {
            $this->update(['calendar_token' => Str::uuid()->toString()]);
        }

        return $this->calendar_token;
    }

    /**
     * Check if the user has accepted the referral program terms.
     */
    public function hasAcceptedReferralTerms(): bool
    {
        return $this->referral_terms_accepted_at !== null;
    }

    public function hasAvatar(): bool
    {
        return $this->avatar_path !== null;
    }

    public function getAvatarUrl(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return storage_url($this->avatar_path);
    }

    public function deleteAvatar(): void
    {
        if ($this->avatar_path) {
            Storage::delete($this->avatar_path);
            $this->update(['avatar_path' => null]);
        }
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the current incomplete daily follow-up activity for this user.
     */
    public function currentFollowUp(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')
            ->where('automation_key', 'daily_followup')
            ->where('is_completed', false)
            ->orderBy('due_at');
    }

    /**
     * Get all daily follow-up activities for this user.
     */
    public function followUpActivities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')
            ->where('automation_key', 'daily_followup');
    }

    public function isLost(): bool
    {
        return $this->follow_up_status === 'lost';
    }
}
