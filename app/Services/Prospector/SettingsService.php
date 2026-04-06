<?php

namespace App\Services\Prospector;

use App\Models\Prospector\ClaimstakerSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'claimstaker:settings';

    private const CACHE_TTL = 300;

    /**
     * Default values for all settings.
     * These are used when no database value exists.
     *
     * @var array<string, array{value: mixed, type: string}>
     */
    private const DEFAULTS = [
        // Feature flags
        'enabled' => ['value' => true, 'type' => 'boolean'],
        'bridge_enabled' => ['value' => false, 'type' => 'boolean'],

        // Dialer defaults
        'defaults.wrap_up_seconds' => ['value' => 30, 'type' => 'integer'],
        'defaults.max_concurrent_lines' => ['value' => 1, 'type' => 'integer'],
        'defaults.ring_timeout_seconds' => ['value' => 30, 'type' => 'integer'],
        'defaults.amd_enabled' => ['value' => true, 'type' => 'boolean'],
        'defaults.recording_enabled' => ['value' => false, 'type' => 'boolean'],

        // TCPA
        'tcpa.calling_hours_start' => ['value' => '08:00', 'type' => 'string'],
        'tcpa.calling_hours_end' => ['value' => '21:00', 'type' => 'string'],
        'tcpa.max_attempts_per_contact' => ['value' => 3, 'type' => 'integer'],
        'tcpa.min_hours_between_attempts' => ['value' => 4, 'type' => 'integer'],

        // Rate limits
        'rate_limits.calls_per_second' => ['value' => 1, 'type' => 'integer'],
        'rate_limits.daily_call_limit' => ['value' => 1000, 'type' => 'integer'],
        'rate_limits.daily_sms_limit' => ['value' => 500, 'type' => 'integer'],

        // Python AI service
        'python.enabled' => ['value' => false, 'type' => 'boolean'],
        'python.url' => ['value' => 'http://127.0.0.1:8100', 'type' => 'string'],
        'python.timeout' => ['value' => 60, 'type' => 'integer'],

        // AI / xAI Grok
        'ai.xai_api_key' => ['value' => '', 'type' => 'encrypted'],
        'ai.xai_voice' => ['value' => 'ara', 'type' => 'string'],
        'ai.laravel_callback_url' => ['value' => '', 'type' => 'string'],

        // Model Bridge mappings
        'bridge.company_model' => ['value' => '', 'type' => 'string'],
        'bridge.company_fields' => ['value' => '{}', 'type' => 'json'],
        'bridge.contact_model' => ['value' => '', 'type' => 'string'],
        'bridge.contact_company_key' => ['value' => '', 'type' => 'string'],
        'bridge.contact_fields' => ['value' => '{}', 'type' => 'json'],
    ];

    /**
     * Get a setting value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->getAllCached();

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        // Check DEFAULTS for the default value (cast same as DB values)
        if ($default === null && isset(self::DEFAULTS[$key])) {
            $meta = self::DEFAULTS[$key];

            return match ($meta['type'] ?? 'string') {
                'json' => is_string($meta['value']) ? (json_decode($meta['value'], true) ?? $meta['value']) : $meta['value'],
                'boolean' => (bool) $meta['value'],
                'integer' => (int) $meta['value'],
                default => $meta['value'],
            };
        }

        return $default;
    }

    /**
     * Set a setting value.
     */
    public function set(string $key, mixed $value, ?string $type = null): void
    {
        $type = $type ?? (self::DEFAULTS[$key]['type'] ?? 'string');

        ClaimstakerSetting::setValue($key, $value, $type);

        $this->clearCache();
    }

    /**
     * Set multiple settings at once.
     *
     * @param  array<string, mixed>  $settings
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $type = self::DEFAULTS[$key]['type'] ?? 'string';
            ClaimstakerSetting::setValue($key, $value, $type);
        }

        $this->clearCache();
    }

    /**
     * Get all settings as a flat key-value array (cached).
     *
     * @return array<string, mixed>
     */
    public function getAllCached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $dbSettings = ClaimstakerSetting::all()->mapWithKeys(function ($setting) {
                return [$setting->key => $setting->getCastValue()];
            })->all();

            // Merge defaults with database values (DB takes precedence)
            $result = [];
            foreach (self::DEFAULTS as $key => $meta) {
                if (array_key_exists($key, $dbSettings)) {
                    $result[$key] = $dbSettings[$key];
                } else {
                    // Cast default values the same way DB values are cast
                    $result[$key] = match ($meta['type'] ?? 'string') {
                        'json' => is_string($meta['value']) ? (json_decode($meta['value'], true) ?? $meta['value']) : $meta['value'],
                        'boolean' => (bool) $meta['value'],
                        'integer' => (int) $meta['value'],
                        default => $meta['value'],
                    };
                }
            }

            // Include any DB settings not in defaults (custom settings)
            foreach ($dbSettings as $key => $value) {
                if (! array_key_exists($key, $result)) {
                    $result[$key] = $value;
                }
            }

            return $result;
        });
    }

    /**
     * Clear the settings cache.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get all default keys and their metadata.
     *
     * @return array<string, array{value: mixed, type: string}>
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }
}
