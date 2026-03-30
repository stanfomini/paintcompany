<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'prospector_contacts';

    protected $fillable = [
        'ulid',
        'company_id',
        'external_id',
        'source',
        'first_name',
        'last_name',
        'phone',
        'phone_2',
        'email',
        'address',
        'city',
        'state',
        'zip',
        'timezone',
        'tags',
        'custom_fields',
        'do_not_call',
        'last_contacted_at',
        'last_disposition',
        'total_calls',
        'total_sms',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'custom_fields' => 'array',
            'do_not_call' => 'boolean',
            'last_contacted_at' => 'datetime',
            'total_calls' => 'integer',
            'total_sms' => 'integer',
            'priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $model) {
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

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'prospector_campaign_contact', 'contact_id', 'campaign_id')
            ->withPivot('status', 'sort_order', 'attempts', 'last_attempted_at', 'last_disposition', 'added_at');
    }

    public function campaignEntries(): HasMany
    {
        return $this->hasMany(CampaignContact::class, 'contact_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeCallable(Builder $query): void
    {
        $query->where('do_not_call', false)->whereNull('deleted_at');
    }

    public function scopeWithTag(Builder $query, string $tag): void
    {
        $query->whereJsonContains('tags', $tag);
    }

    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term) {
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    public function scopeHighPriority(Builder $query): void
    {
        $query->orderByDesc('priority');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Get the contact's full name.
     */
    public function getFullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Format the primary phone number for display as (720) 555-1234.
     */
    public function getDisplayPhone(): string
    {
        $digits = preg_replace('/\D/', '', $this->phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }

        return $this->phone;
    }

    /**
     * Mark this contact as recently contacted.
     */
    public function markContacted(?string $disposition = null): void
    {
        $this->update([
            'last_contacted_at' => now(),
            'last_disposition' => $disposition,
            'total_calls' => $this->total_calls + 1,
        ]);
    }

    /**
     * Check if this contact is on the do-not-call list.
     */
    public function isDnc(): bool
    {
        return $this->do_not_call;
    }

    /**
     * Extract the area code from the primary phone number.
     */
    public function getAreaCode(): string
    {
        $digits = preg_replace('/\D/', '', $this->phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return substr($digits, 0, 3);
    }
}
