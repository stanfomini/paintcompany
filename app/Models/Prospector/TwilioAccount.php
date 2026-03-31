<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Twilio\Rest\Client;

class TwilioAccount extends Model
{
    use HasFactory;

    protected $table = 'prospector_twilio_accounts';

    protected $fillable = [
        'ulid',
        'name',
        'account_sid',
        'auth_token',
        'api_key_sid',
        'api_key_secret',
        'twiml_app_sid',
        'is_primary',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'account_sid' => 'encrypted',
            'auth_token' => 'encrypted',
            'api_key_sid' => 'encrypted',
            'api_key_secret' => 'encrypted',
            'twiml_app_sid' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TwilioAccount $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'twilio_account_id');
    }

    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'twilio_account_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopePrimary(Builder $query): void
    {
        $query->where('is_primary', true);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Get a configured Twilio REST client for this account.
     */
    public function getClient(): Client
    {
        return new Client($this->account_sid, $this->auth_token);
    }

    /**
     * Get a masked version of the account SID for display.
     *
     * Shows first 4 and last 4 characters with **** in the middle.
     */
    public function getMaskedSid(): string
    {
        $sid = $this->account_sid;

        if (strlen($sid) <= 8) {
            return $sid;
        }

        return substr($sid, 0, 4).'****'.substr($sid, -4);
    }
}
