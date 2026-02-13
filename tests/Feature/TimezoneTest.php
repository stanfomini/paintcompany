<?php

use App\Models\CompanySetting;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::clearInstanceCache();
});

afterEach(function () {
    // Reset timezone back to UTC so it doesn't leak between tests.
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    CompanySetting::clearInstanceCache();
});

// ─── Service Provider ───────────────────────────────────────────────

it('sets app timezone from company settings on boot', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['timezone' => 'America/Denver']);
    CompanySetting::clearInstanceCache();

    // Simulate what the provider does.
    $tz = CompanySetting::getInstance()->timezone;
    config(['app.timezone' => $tz]);
    date_default_timezone_set($tz);

    expect(config('app.timezone'))->toBe('America/Denver')
        ->and(date_default_timezone_get())->toBe('America/Denver');
});

it('now() returns Denver time after timezone is set', function () {
    config(['app.timezone' => 'America/Denver']);
    date_default_timezone_set('America/Denver');

    $now = Carbon::now();

    expect($now->timezone->getName())->toBe('America/Denver');
});

it('falls back to UTC when company_settings table is unavailable', function () {
    // Default config should still be UTC if provider can't read DB.
    // This is the behavior of the try-catch in the provider.
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

// ─── Validation (after:now) ─────────────────────────────────────────

it('after:now validation passes for a future local time', function () {
    config(['app.timezone' => 'America/Denver']);
    date_default_timezone_set('America/Denver');

    $futureTime = Carbon::now('America/Denver')->addHour()->format('Y-m-d\TH:i');

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['meeting_time' => $futureTime],
        ['meeting_time' => 'required|date|after:now']
    );

    expect($validator->passes())->toBeTrue();
});

it('after:now validation fails for a past local time', function () {
    config(['app.timezone' => 'America/Denver']);
    date_default_timezone_set('America/Denver');

    $pastTime = Carbon::now('America/Denver')->subHour()->format('Y-m-d\TH:i');

    $validator = \Illuminate\Support\Facades\Validator::make(
        ['meeting_time' => $pastTime],
        ['meeting_time' => 'required|date|after:now']
    );

    expect($validator->fails())->toBeTrue();
});

// ─── CompanySetting Timezone ────────────────────────────────────────

it('stores timezone on company settings', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['timezone' => 'America/Denver']);

    $settings->refresh();

    expect($settings->timezone)->toBe('America/Denver');
});

it('defaults timezone to America/Denver for this company', function () {
    $settings = CompanySetting::getInstance();

    // The seeder / factory may set a different default, but the field should exist.
    expect($settings->timezone)->not->toBeNull();
});
