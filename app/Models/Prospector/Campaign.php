<?php

namespace App\Models\Prospector;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory;

    protected $table = 'prospector_campaigns';

    protected $fillable = [
        'ulid',
        'company_id',
        'name',
        'description',
        'status',
        'phone_number_id',
        'use_local_presence',
        'dialer_mode',
        'parallel_lines',
        'priority',
        'contact_count',
        'calls_attempted',
        'calls_connected',
        'auto_vm_drop',
        'default_rvm_template_id',
        'created_by',
        'max_calls_per_day',
        'max_total_attempts',
        'max_days_in_campaign',
        'min_minutes_between',
        'next_campaign_id',
        'calling_hours_start',
        'calling_hours_end',
        'auto_dial',
    ];

    protected function casts(): array
    {
        return [
            'use_local_presence' => 'boolean',
            'parallel_lines' => 'integer',
            'priority' => 'integer',
            'contact_count' => 'integer',
            'calls_attempted' => 'integer',
            'calls_connected' => 'integer',
            'auto_vm_drop' => 'boolean',
            'auto_dial' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'phone_number_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'prospector_campaign_contact', 'campaign_id', 'contact_id')
            ->withPivot('status', 'sort_order', 'attempts', 'last_attempted_at', 'last_disposition', 'added_at');
    }

    public function campaignContacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class, 'campaign_id');
    }

    public function callScripts(): HasMany
    {
        return $this->hasMany(CallScript::class, 'campaign_id');
    }

    public function defaultRvmTemplate(): BelongsTo
    {
        return $this->belongsTo(RvmTemplate::class, 'default_rvm_template_id');
    }

    public function nextCampaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'next_campaign_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeWithStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if this campaign is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the number of contacts still queued for this campaign.
     */
    public function getQueuedCount(): int
    {
        return $this->campaignContacts()->where('status', 'queued')->count();
    }

    /**
     * Get the number of contacts that have been completed in this campaign.
     */
    public function getCompletedCount(): int
    {
        return $this->campaignContacts()->where('status', 'completed')->count();
    }

    /**
     * Get the connection rate as a percentage.
     */
    public function getConnectionRate(): float
    {
        if ($this->calls_attempted === 0) {
            return 0.0;
        }

        return round(($this->calls_connected / $this->calls_attempted) * 100, 2);
    }

    /**
     * Refresh the contact_count from the actual pivot table count.
     */
    public function refreshContactCount(): void
    {
        $this->update([
            'contact_count' => $this->contacts()->count(),
        ]);
    }

    public function isContactCallable(\App\Models\Prospector\Contact $contact): bool
    {
        if (! $contact->timezone) {
            return true;
        }

        $contactTime = now()->timezone($contact->timezone);
        $hour = (int) $contactTime->format('H');
        $timeStr = $contactTime->format('H:i');

        // TCPA floor: 8am-9pm in contact's local time
        if ($hour < 8 || $hour >= 21) {
            return false;
        }

        // Campaign override (tighter only)
        if ($this->calling_hours_start && $timeStr < $this->calling_hours_start) {
            return false;
        }
        if ($this->calling_hours_end && $timeStr >= $this->calling_hours_end) {
            return false;
        }

        return true;
    }
}
