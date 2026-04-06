<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $table = 'prospector_agents';

    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'permissions',
        'is_active',
        'status',
        'status_changed_at',
        'max_concurrent_lines',
        'wrap_up_seconds',
        'daily_call_count',
        'daily_talk_minutes',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'max_concurrent_lines' => 'integer',
            'wrap_up_seconds' => 'integer',
            'daily_call_count' => 'integer',
            'daily_talk_minutes' => 'integer',
            'settings' => 'array',
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function dialerSessions(): HasMany
    {
        return $this->hasMany(DialerSession::class, 'agent_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'agent_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'prospector_agent_company');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', 'available');
    }

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeOnline(Builder $query): void
    {
        $query->where('status', '!=', 'offline');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Get all company IDs the agent is assigned to.
     * Falls back to the single company_id FK if no pivot entries exist.
     *
     * @return array<int, int>
     */
    public function getCompanyIds(): array
    {
        $ids = $this->companies()->pluck('prospector_companies.id')->all();

        if (empty($ids) && $this->company_id) {
            return [$this->company_id];
        }

        return $ids;
    }

    /**
     * Check if the agent is available for new calls.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    /**
     * Check if the agent has the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === \App\Prospector\Permissions::ROLE_ADMIN;
    }

    /**
     * Check if the agent has supervisor-level access (supervisor or admin).
     */
    public function isSupervisor(): bool
    {
        return in_array($this->role, [
            \App\Prospector\Permissions::ROLE_ADMIN,
            \App\Prospector\Permissions::ROLE_SUPERVISOR,
        ]);
    }

    /**
     * Check if the agent can act as an agent (all roles can).
     */
    public function isAgent(): bool
    {
        return true;
    }

    /**
     * Get the agent record for the currently authenticated user.
     */
    public static function forCurrentUser(): ?self
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        if (! $user) {
            return null;
        }

        return static::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Check if any admin agent exists in the system.
     */
    public static function hasAnyAdmin(): bool
    {
        return static::where('role', \App\Prospector\Permissions::ROLE_ADMIN)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Get the effective list of permissions for this agent.
     * If permissions JSON is set, use it (custom overrides).
     * Otherwise, fall back to role defaults.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(): array
    {
        if (is_array($this->permissions) && ! empty($this->permissions)) {
            return $this->permissions;
        }

        return \App\Prospector\Permissions::defaultsForRole($this->role ?? 'agent');
    }

    /**
     * Check if this agent has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Admins always have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->effectivePermissions(), true);
    }

    /**
     * Check if this agent has ANY of the given permissions.
     *
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the agent status to available.
     */
    public function goAvailable(): void
    {
        $this->update([
            'status' => 'available',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Set the agent status to nearby.
     */
    public function goNearby(): void
    {
        $this->update([
            'status' => 'nearby',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Set the agent status to on_call.
     */
    public function goOnCall(): void
    {
        $this->update([
            'status' => 'on_call',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Set the agent status to wrap_up.
     */
    public function goWrapUp(): void
    {
        $this->update([
            'status' => 'wrap_up',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Set the agent status to break.
     */
    public function goBreak(): void
    {
        $this->update([
            'status' => 'break',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Set the agent status to offline.
     */
    public function goOffline(): void
    {
        $this->update([
            'status' => 'offline',
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Increment the daily call count by one.
     */
    public function incrementCallCount(): void
    {
        $this->increment('daily_call_count');
    }

    /**
     * Reset daily call count and talk minutes to zero.
     */
    public function resetDailyCounts(): void
    {
        $this->update([
            'daily_call_count' => 0,
            'daily_talk_minutes' => 0,
        ]);
    }
}
