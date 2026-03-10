<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AutomationWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token',
        'is_active',
        'auth_type',
        'auth_header',
        'auth_value',
        'last_payload',
        'last_triggered_at',
        'trigger_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_payload' => 'array',
            'last_triggered_at' => 'datetime',
            'trigger_count' => 'integer',
            'auth_value' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AutomationWebhook $webhook) {
            if (empty($webhook->token)) {
                $webhook->token = (string) Str::uuid();
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getWebhookUrl(): string
    {
        return route('webhook.automation', $this->token);
    }

    /**
     * Get the full webhook URL including API key as query parameter when applicable.
     */
    public function getFullWebhookUrl(): string
    {
        $url = $this->getWebhookUrl();

        if ($this->auth_type === 'query_param' && $this->auth_header && $this->auth_value) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                .urlencode($this->auth_header).'='.urlencode($this->auth_value);
        }

        return $url;
    }

    public function validateAuth(Request $request): bool
    {
        return match ($this->auth_type) {
            'none' => true,
            'api_key' => $this->auth_header
                && $request->header($this->auth_header) === $this->auth_value,
            'bearer' => $request->bearerToken() === $this->auth_value,
            'query_param' => $this->auth_header
                && $request->query($this->auth_header) === $this->auth_value,
            default => false,
        };
    }
}
