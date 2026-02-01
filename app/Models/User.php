<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

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

    /**
     * Get the listings managed by this agent.
     */
    public function agentListings(): HasMany
    {
        return $this->hasMany(AgentListing::class, 'agent_id');
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

    public function isActive(): bool
    {
        return $this->is_active;
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
