<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

/**
 * @property-read LeadSource|null $leadSource
 */
class Customer extends Model
{
    use HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (Customer $customer) {
            if ($customer->address && $customer->city) {
                $exists = $customer->properties()
                    ->where('address', $customer->address)
                    ->where('city', $customer->city)
                    ->exists();

                if (! $exists) {
                    Property::create([
                        'customer_id' => $customer->id,
                        'address' => $customer->address,
                        'city' => $customer->city,
                        'state' => $customer->state ?? '',
                        'zip' => $customer->zip ?? '',
                        'type' => 'residential',
                    ]);
                }
            }

        });

        // Sync CRM phone → customer_profiles.phone so SMS lookups find the number
        static::created(function (Customer $customer) {
            if ($customer->phone && $customer->user_id) {
                $profile = $customer->user?->customerProfile;
                if ($profile && ! $profile->phone) {
                    $profile->update(['phone' => $customer->phone]);
                }
            }

            // Auto-link unlinked SMS threads matching this phone
            if ($customer->phone) {
                static::autoLinkSmsThreads($customer);
            }
        });

        static::updated(function (Customer $customer) {
            if ($customer->wasChanged('phone') && $customer->phone && $customer->user_id) {
                $customer->user?->customerProfile?->update(['phone' => $customer->phone]);
            }

            // Auto-link unlinked SMS threads when phone changes
            if ($customer->wasChanged('phone') && $customer->phone) {
                static::autoLinkSmsThreads($customer);
            }
        });
    }

    public const SOURCES = [
        'online' => 'Website',
        'referral' => 'Referral',
        'agent' => 'Agent',
        'walk_in' => 'Walk-In',
        'door_knock' => 'Door Knock',
        'google_ads' => 'Google Ads',
        'other' => 'Other',
    ];

    protected $fillable = [
        'user_id',
        'created_by',
        'assigned_to',
        'referred_by',
        'property_id',
        'lead_source_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'notes',
        'status',
        'sms_marketing_consent_at',
        'source',
        'estimate_preference',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
            'sms_marketing_consent_at' => 'datetime',
        ];
    }

    /**
     * Get the full name of the customer.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the full address of the customer.
     */
    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->state ? "{$this->state} {$this->zip}" : $this->zip,
        ]);

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    /**
     * Get the user account associated with this customer (if they have portal access).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the internal user who created this customer record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the internal user assigned to manage this customer.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the agent who referred this customer.
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Get the property this customer was referred from (origin property/listing).
     */
    public function originProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * Get the lead source for this customer.
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * Get lead source options from the database.
     */
    public static function getSourceOptions(): array
    {
        return LeadSource::getOptions();
    }

    /**
     * Get the properties associated with this customer.
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Get the deals associated with this customer.
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function projectPreviews(): HasMany
    {
        return $this->hasMany(ProjectPreview::class);
    }

    /**
     * Get collaborations where this customer is a collaborator.
     */
    public function collaborations(): HasMany
    {
        return $this->hasMany(DealCollaborator::class);
    }

    /**
     * Get active collaborations for this customer.
     */
    public function activeCollaborations(): HasMany
    {
        return $this->hasMany(DealCollaborator::class)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Get the estimates for this customer through deals.
     */
    public function estimates(): HasManyThrough
    {
        return $this->hasManyThrough(Estimate::class, Deal::class);
    }

    /**
     * Get the appointment bookings for this customer.
     */
    public function appointmentBookings(): HasMany
    {
        return $this->hasMany(AppointmentBooking::class);
    }

    /**
     * Get the activities for this customer.
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
     * Scope to filter only active customers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter inactive customers.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope to filter customers referred by a specific agent.
     */
    public function scopeReferredByAgent($query, User $agent)
    {
        return $query->where('referred_by', $agent->id);
    }

    /**
     * Scope to filter customers with a referring agent.
     */
    public function scopeWithAgent($query)
    {
        return $query->whereNotNull('referred_by');
    }

    /**
     * Scope to search customers by name, email, or phone.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    /**
     * Get all duplicate flags for this customer.
     */
    public function duplicateFlags(): HasMany
    {
        return $this->hasMany(CustomerDuplicateFlag::class);
    }

    /**
     * Get pending duplicate flags for this customer.
     */
    public function pendingDuplicateFlags(): HasMany
    {
        return $this->hasMany(CustomerDuplicateFlag::class)->where('status', 'pending');
    }

    /**
     * Check if the customer has any pending duplicate flags.
     */
    public function hasPendingDuplicates(): bool
    {
        return $this->pendingDuplicateFlags()->exists();
    }

    /**
     * Check if the customer has portal access.
     */
    public function hasPortalAccess(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Check if the customer has SMS marketing consent.
     */
    public function hasSmsMarketingConsent(): bool
    {
        return $this->sms_marketing_consent_at !== null;
    }

    /**
     * Check if the customer is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Route notifications for the Twilio SMS channel.
     */
    public function routeNotificationForTwilio(): ?string
    {
        return $this->phone;
    }

    /**
     * Route notifications for the Mail channel.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * Auto-link unlinked SMS support threads whose subject matches this customer's phone.
     */
    private static function autoLinkSmsThreads(Customer $customer): void
    {
        $digits = preg_replace('/\D/', '', $customer->phone);
        $last10 = substr($digits, -10);

        if (strlen($last10) < 10) {
            return;
        }

        ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->whereNull('customer_id')
            ->where('subject', 'LIKE', "%{$last10}%")
            ->update(['customer_id' => $customer->id]);

        // Also update SmsSession records
        SmsSession::where('phone_number', 'LIKE', "%{$last10}%")
            ->whereNull('customer_id')
            ->update(['customer_id' => $customer->id]);
    }
}
