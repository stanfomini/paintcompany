<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_source_id',
        'token',
        'is_active',
        'auth_type',
        'auth_header',
        'auth_value',
        'content_type',
        'sample_payload',
        'last_payload_at',
        'total_leads',
        'default_assigned_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sample_payload' => 'array',
            'last_payload_at' => 'datetime',
            'total_leads' => 'integer',
            'auth_value' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            if (empty($endpoint->token)) {
                $endpoint->token = (string) Str::uuid();
            }
        });
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(WebhookFieldMapping::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function defaultAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getWebhookUrl(): string
    {
        return route('webhook.lead', $this->token);
    }

    public function validateAuth(Request $request): bool
    {
        return match ($this->auth_type) {
            'none' => true,
            'api_key' => $this->auth_header
                && $request->header($this->auth_header) === $this->auth_value,
            'bearer' => $request->bearerToken() === $this->auth_value,
            default => false,
        };
    }
}
