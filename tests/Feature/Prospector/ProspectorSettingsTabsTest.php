<?php

use App\Models\User;
use App\Services\Prospector\SettingsService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('loads the dialer tab on the settings page', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('activeTab', 'dialer')
        ->assertSee('Dialer Defaults')
        ->assertSee('Rate Limits')
        ->assertSee('Wrap-Up Time')
        ->assertSee('Max Concurrent Lines');
});

it('loads the tcpa tab on the settings page', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('activeTab', 'tcpa')
        ->assertSee('TCPA Compliance')
        ->assertSee('Calling Hours Start')
        ->assertSee('Max Attempts Per Contact');
});

it('loads the python tab on the settings page', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('activeTab', 'python')
        ->assertSee('Python AI Service')
        ->assertSee('Model Bridge')
        ->assertSee('Enable Python Service');
});

it('saves dialer defaults to the database', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('wrapUpSeconds', 45)
        ->set('maxConcurrentLines', 3)
        ->set('ringTimeoutSeconds', 25)
        ->set('amdEnabled', false)
        ->call('saveDialerDefaults')
        ->assertDispatched('notify');

    $settings = app(SettingsService::class);
    expect($settings->get('defaults.wrap_up_seconds'))->toBe(45)
        ->and($settings->get('defaults.max_concurrent_lines'))->toBe(3)
        ->and($settings->get('defaults.ring_timeout_seconds'))->toBe(25)
        ->and($settings->get('defaults.amd_enabled'))->toBeFalse();
});

it('saves tcpa settings to the database', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('callingHoursStart', '09:00')
        ->set('callingHoursEnd', '20:00')
        ->set('maxAttemptsPerContact', 5)
        ->set('minHoursBetweenAttempts', 6)
        ->call('saveTcpaSettings')
        ->assertDispatched('notify');

    $settings = app(SettingsService::class);
    expect($settings->get('tcpa.calling_hours_start'))->toBe('09:00')
        ->and($settings->get('tcpa.calling_hours_end'))->toBe('20:00')
        ->and($settings->get('tcpa.max_attempts_per_contact'))->toBe(5)
        ->and($settings->get('tcpa.min_hours_between_attempts'))->toBe(6);
});

it('saves rate limits to the database', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('callsPerSecond', 2)
        ->set('dailyCallLimit', 500)
        ->set('dailySmsLimit', 250)
        ->call('saveRateLimits')
        ->assertDispatched('notify');

    $settings = app(SettingsService::class);
    expect($settings->get('rate_limits.calls_per_second'))->toBe(2)
        ->and($settings->get('rate_limits.daily_call_limit'))->toBe(500)
        ->and($settings->get('rate_limits.daily_sms_limit'))->toBe(250);
});

it('saves python settings to the database', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('pythonEnabled', true)
        ->set('pythonUrl', 'http://localhost:9000')
        ->set('pythonTimeout', 120)
        ->call('savePythonSettings')
        ->assertDispatched('notify');

    $settings = app(SettingsService::class);
    expect($settings->get('python.enabled'))->toBeTrue()
        ->and($settings->get('python.url'))->toBe('http://localhost:9000')
        ->and($settings->get('python.timeout'))->toBe(120);
});

it('saves bridge settings to the database', function () {
    Livewire::actingAs($this->user)
        ->test('pages::prospector.settings')
        ->set('bridgeEnabled', true)
        ->call('saveBridgeSettings')
        ->assertDispatched('notify');

    $settings = app(SettingsService::class);
    expect($settings->get('bridge_enabled'))->toBeTrue();
});

it('loads saved settings on mount', function () {
    $settings = app(SettingsService::class);
    $settings->setMany([
        'defaults.wrap_up_seconds' => 60,
        'defaults.max_concurrent_lines' => 4,
        'tcpa.calling_hours_start' => '10:00',
        'rate_limits.daily_call_limit' => 2000,
        'python.enabled' => true,
        'bridge_enabled' => true,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::prospector.settings');

    expect($component->get('wrapUpSeconds'))->toBe(60)
        ->and($component->get('maxConcurrentLines'))->toBe(4)
        ->and($component->get('callingHoursStart'))->toBe('10:00')
        ->and($component->get('dailyCallLimit'))->toBe(2000)
        ->and($component->get('pythonEnabled'))->toBeTrue()
        ->and($component->get('bridgeEnabled'))->toBeTrue();
});

it('denies non-internal users from accessing the settings page', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('prospector.settings'))
        ->assertRedirect();
});
