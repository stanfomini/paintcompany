<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MessengerSetting extends Model
{
    /**
     * Static in-memory cache for the singleton instance.
     * Prevents repeated DB queries within a single request.
     */
    protected static ?self $resolvedInstance = null;

    protected $fillable = [
        'app_id',
        'page_id',
        'page_access_token',
        'app_secret',
        'verify_token',
        'webhook_url',
        'default_assigned_to',
        'website_widget_enabled',
        'widget_greeting_logged_in',
        'widget_greeting_logged_out',
        'widget_theme_color',
        'is_connected',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'website_widget_enabled' => 'boolean',
            'is_connected' => 'boolean',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * Get the singleton messenger settings instance.
     * Creates a default record if none exists.
     * Uses static in-memory cache to avoid repeated DB queries per request.
     */
    public static function getInstance(): self
    {
        return static::$resolvedInstance ??= static::firstOrCreate([], [
            'verify_token' => static::generateVerifyToken(),
            'widget_theme_color' => '#0084ff',
        ]);
    }

    /**
     * Clear the static instance cache.
     * Called automatically on save/delete to ensure freshness within the same request.
     */
    public static function clearInstanceCache(): void
    {
        static::$resolvedInstance = null;
    }

    /**
     * Boot the model and register events to clear the cache on changes.
     */
    protected static function booted(): void
    {
        static::saved(function () {
            static::clearInstanceCache();
        });

        static::deleted(function () {
            static::clearInstanceCache();
        });
    }

    /**
     * Get the default assigned user.
     */
    public function defaultAssignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assigned_to');
    }

    /**
     * Get the webhook URL for Facebook verification.
     */
    public function getWebhookUrl(): string
    {
        return route('webhook.facebook.receive');
    }

    /**
     * Check if the messenger integration is fully configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->page_access_token) && ! empty($this->app_secret);
    }

    /**
     * Generate a new verify token UUID.
     */
    public static function generateVerifyToken(): string
    {
        return Str::uuid()->toString();
    }
}
