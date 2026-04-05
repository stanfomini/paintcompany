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
        return $this->role === 'admin';
    }

    /**
     * Check if the agent has supervisor-level access (supervisor or admin).
     */
    public function isSupervisor(): bool
    {
        return in_array($this->role, ['supervisor', 'admin']);
    }

    /**
     * Check if the agent can act as an agent (all roles can).
     */
    public function isAgent(): bool
    {
        return true;
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
