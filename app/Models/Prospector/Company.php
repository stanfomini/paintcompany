<?php

namespace App\Models\Prospector;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $table = 'prospector_companies';

    protected $fillable = [
        'ulid',
        'name',
        'twilio_account_id',
        'main_phone_number_id',
        'timezone',
        'business_hours_start',
        'business_hours_end',
        'business_days',
        'webhook_secret',
        'settings',
        'is_active',
        'color',
        'notes',
        'default_campaign_id',
        'default_rvm_template_id',
        'default_disposition_sequences',
    ];

    protected function casts(): array
    {
        return [
            'business_days' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'default_disposition_sequences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }

            if (empty($model->webhook_secret)) {
                $model->webhook_secret = Str::random(64);
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function twilioAccount(): BelongsTo
    {
        return $this->belongsTo(TwilioAccount::class, 'twilio_account_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'company_id');
    }

    public function mainPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'main_phone_number_id');
    }

    public function defaultRvmTemplate(): BelongsTo
    {
        return $this->belongsTo(RvmTemplate::class, 'default_rvm_template_id');
    }

    public function bookingLinks(): HasMany
    {
        return $this->hasMany(CompanyBookingLink::class, 'company_id');
    }

    public function defaultCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'default_campaign_id');
    }

    public function callScripts(): HasMany
    {
        return $this->hasMany(CallScript::class, 'company_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'company_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'company_id');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'prospector_agent_company');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if the current time falls within company business hours.
     */
    public function isBusinessHours(): bool
    {
        if (! $this->business_hours_start || ! $this->business_hours_end) {
            return true;
        }

        $now = Carbon::now($this->timezone ?? 'America/Denver');
        $dayOfWeek = strtolower($now->format('l'));

        $businessDays = $this->business_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

        if (! in_array($dayOfWeek, $businessDays)) {
            return false;
        }

        $start = Carbon::parse($this->business_hours_start, $this->timezone ?? 'America/Denver');
        $end = Carbon::parse($this->business_hours_end, $this->timezone ?? 'America/Denver');

        return $now->between($start, $end);
    }

    /**
     * Get the active IVR flow based on current business hours.
     * Returns the business_hours or after_hours flow config, or null if IVR disabled.
     *
     * @return array{greeting: string, options: array, no_input_action: string}|null
     */
    public function getActiveIvrFlow(): ?array
    {
        $settings = $this->settings ?? [];
        $ivr = $settings['ivr'] ?? null;

        if (! $ivr || ! ($ivr['enabled'] ?? false)) {
            return null;
        }

        // Dual-flow config: check for business_hours/after_hours keys
        if (isset($ivr['business_hours']) || isset($ivr['after_hours'])) {
            return $this->isBusinessHours()
                ? ($ivr['business_hours'] ?? null)
                : ($ivr['after_hours'] ?? null);
        }

        // Legacy single-flow config: greeting + options at top level
        if (isset($ivr['greeting'])) {
            return [
                'greeting' => $ivr['greeting'],
                'options' => $ivr['options'] ?? [],
                'no_input_action' => 'voicemail',
            ];
        }

        return null;
    }

    /**
     * Get all active phone numbers that have an assigned role.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function getAvailableNumbers(): Collection
    {
        return $this->phoneNumbers()
            ->where('is_active', true)
            ->where('role', '!=', 'unassigned')
            ->get();
    }

    /**
     * Get the main phone number for this company.
     */
    public function getMainNumber(): ?PhoneNumber
    {
        return $this->mainPhoneNumber;
    }

    /**
     * Find a shadow/local-presence phone number matching the given area code.
     */
    public function getLocalPresenceNumber(string $areaCode): ?PhoneNumber
    {
        return $this->phoneNumbers()
            ->where('is_active', true)
            ->where('area_code', $areaCode)
            ->where('role', 'shadow')
            ->first();
    }
}
