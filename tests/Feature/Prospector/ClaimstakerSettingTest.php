<?php

use App\Models\Prospector\ClaimstakerSetting;
use App\Services\Prospector\SettingsService;
use Illuminate\Support\Facades\Cache;

// ──────────────────────────────────────
// ClaimstakerSetting Model
// ──────────────────────────────────────

it('creates a setting with key and value', function () {
    ClaimstakerSetting::setValue('test_key', 'test_value');

    $setting = ClaimstakerSetting::where('key', 'test_key')->first();

    expect($setting)->not->toBeNull()
        ->and($setting->key)->toBe('test_key')
        ->and($setting->value)->toBe('test_value')
        ->and($setting->type)->toBe('string');
});

it('gets a string setting value', function () {
    ClaimstakerSetting::setValue('site_name', 'Apex');

    expect(ClaimstakerSetting::getValue('site_name'))->toBe('Apex');
});

it('returns default when setting does not exist', function () {
    expect(ClaimstakerSetting::getValue('nonexistent', 'fallback'))->toBe('fallback');
});

it('returns null when setting does not exist and no default', function () {
    expect(ClaimstakerSetting::getValue('nonexistent'))->toBeNull();
});

it('casts boolean setting correctly', function () {
    ClaimstakerSetting::setValue('feature_on', true, 'boolean');
    ClaimstakerSetting::setValue('feature_off', false, 'boolean');

    expect(ClaimstakerSetting::getValue('feature_on'))->toBeTrue()
        ->and(ClaimstakerSetting::getValue('feature_off'))->toBeFalse();
});

it('casts integer setting correctly', function () {
    ClaimstakerSetting::setValue('max_calls', 50, 'integer');

    expect(ClaimstakerSetting::getValue('max_calls'))->toBe(50)
        ->and(ClaimstakerSetting::getValue('max_calls'))->toBeInt();
});

it('casts json setting correctly', function () {
    $data = ['hours' => ['start' => '08:00', 'end' => '21:00']];
    ClaimstakerSetting::setValue('schedule', $data, 'json');

    $result = ClaimstakerSetting::getValue('schedule');

    expect($result)->toBeArray()
        ->and($result['hours']['start'])->toBe('08:00')
        ->and($result['hours']['end'])->toBe('21:00');
});

it('encrypts and decrypts setting correctly', function () {
    ClaimstakerSetting::setValue('api_secret', 'sk-12345', 'encrypted');

    // Raw value in DB should be encrypted (not the plaintext)
    $raw = ClaimstakerSetting::where('key', 'api_secret')->first();
    expect($raw->value)->not->toBe('sk-12345');

    // But getValue decrypts it
    expect(ClaimstakerSetting::getValue('api_secret'))->toBe('sk-12345');
});

it('updates existing setting via setValue', function () {
    ClaimstakerSetting::setValue('counter', 1, 'integer');
    expect(ClaimstakerSetting::getValue('counter'))->toBe(1);

    ClaimstakerSetting::setValue('counter', 99, 'integer');
    expect(ClaimstakerSetting::getValue('counter'))->toBe(99);

    expect(ClaimstakerSetting::where('key', 'counter')->count())->toBe(1);
});

it('handles null value for encrypted type', function () {
    ClaimstakerSetting::setValue('nullable_secret', null, 'encrypted');

    $setting = ClaimstakerSetting::where('key', 'nullable_secret')->first();
    expect($setting->value)->toBeNull()
        ->and($setting->getCastValue())->toBeNull();
});

// ──────────────────────────────────────
// SettingsService
// ──────────────────────────────────────

it('returns default value when no DB setting exists', function () {
    $service = app(SettingsService::class);

    expect($service->get('enabled'))->toBeTrue()
        ->and($service->get('defaults.wrap_up_seconds'))->toBe(30)
        ->and($service->get('tcpa.calling_hours_start'))->toBe('08:00');
});

it('returns explicit default over hardcoded default', function () {
    $service = app(SettingsService::class);

    expect($service->get('nonexistent', 'my_fallback'))->toBe('my_fallback');
});

it('sets and retrieves a setting', function () {
    $service = app(SettingsService::class);

    $service->set('defaults.wrap_up_seconds', 60);

    expect($service->get('defaults.wrap_up_seconds'))->toBe(60);
});

it('sets multiple settings at once', function () {
    $service = app(SettingsService::class);

    $service->setMany([
        'enabled' => false,
        'defaults.wrap_up_seconds' => 45,
        'tcpa.max_attempts_per_contact' => 5,
    ]);

    expect($service->get('enabled'))->toBeFalse()
        ->and($service->get('defaults.wrap_up_seconds'))->toBe(45)
        ->and($service->get('tcpa.max_attempts_per_contact'))->toBe(5);
});

it('clears cache when setting a value', function () {
    $service = app(SettingsService::class);

    // Prime cache
    $service->getAllCached();

    // Set a value (should clear cache)
    $service->set('enabled', false);

    // New get should reflect updated value
    expect($service->get('enabled'))->toBeFalse();
});

it('database value overrides default', function () {
    $service = app(SettingsService::class);

    // Default is 30
    expect($service->get('defaults.wrap_up_seconds'))->toBe(30);

    // Set DB value
    ClaimstakerSetting::setValue('defaults.wrap_up_seconds', 120, 'integer');
    $service->clearCache();

    expect($service->get('defaults.wrap_up_seconds'))->toBe(120);
});

it('includes custom keys not in defaults', function () {
    $service = app(SettingsService::class);

    ClaimstakerSetting::setValue('custom.feature_x', 'enabled');
    $service->clearCache();

    $all = $service->getAllCached();

    expect($all)->toHaveKey('custom.feature_x')
        ->and($all['custom.feature_x'])->toBe('enabled');
});

it('provides all default metadata via getDefaults', function () {
    $defaults = SettingsService::getDefaults();

    expect($defaults)->toBeArray()
        ->toHaveKey('enabled')
        ->toHaveKey('defaults.wrap_up_seconds')
        ->toHaveKey('tcpa.calling_hours_start')
        ->toHaveKey('ai.xai_api_key');

    expect($defaults['enabled']['type'])->toBe('boolean');
    expect($defaults['ai.xai_api_key']['type'])->toBe('encrypted');
});

it('is registered as a singleton', function () {
    $a = app(SettingsService::class);
    $b = app(SettingsService::class);

    expect($a)->toBe($b);
});
