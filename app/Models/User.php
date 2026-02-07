<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'password',
        'type',
        'is_active',
        'calendar_token',
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

    /**
     * Determine if user can access the internal dashboard.
     * Requires internal type + appropriate role/permission.
     */
    public function canAccessInternalDashboard(): bool
    {
        return $this->isInternal() && $this->hasAnyRole(['owner', 'admin', 'manager', 'estimator', 'crew_lead']);
    }

    /**
     * Determine if user can view roadmap.
     */
    public function canViewRoadmap(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('view roadmap');
    }

    /**
     * Determine if user can edit roadmap.
     */
    public function canEditRoadmap(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('edit roadmap');
    }

    /**
     * Determine if user can view KPIs.
     */
    public function canViewKpis(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('view kpis');
    }

    /**
     * Determine if user can view sensitive financial data.
     */
    public function canViewFinancials(): bool
    {
        return $this->isInternal() && $this->hasPermissionTo('view financials');
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
}
