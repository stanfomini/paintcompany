<?php

use App\Models\Prospector\Company;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('company factory generates a color', function () {
    $company = Company::factory()->create();

    expect($company->color)->toStartWith('#')
        ->and(strlen($company->color))->toBe(7);
});

test('getActiveIvrFlow returns null when no IVR settings', function () {
    $company = Company::factory()->create(['settings' => null]);

    expect($company->getActiveIvrFlow())->toBeNull();
});

test('getActiveIvrFlow returns null when IVR is disabled', function () {
    $company = Company::factory()->create([
        'settings' => ['ivr' => ['enabled' => false]],
    ]);

    expect($company->getActiveIvrFlow())->toBeNull();
});

test('getActiveIvrFlow returns business hours flow during business hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-31 10:00', 'America/Denver')); // Tuesday

    $company = Company::factory()->withIvr()->create([
        'timezone' => 'America/Denver',
        'business_hours_start' => '08:00',
        'business_hours_end' => '17:00',
        'business_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ]);

    $flow = $company->getActiveIvrFlow();

    expect($flow)->not->toBeNull()
        ->and($flow['greeting'])->toContain('Press 1 to speak with a team member')
        ->and($flow['no_input_action'])->toBe('route_agent')
        ->and($flow['options'])->toHaveKey('1');

    Carbon::setTestNow();
});

test('getActiveIvrFlow returns after hours flow outside business hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-31 22:00', 'America/Denver')); // Tuesday night

    $company = Company::factory()->withIvr()->create([
        'timezone' => 'America/Denver',
        'business_hours_start' => '08:00',
        'business_hours_end' => '17:00',
        'business_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ]);

    $flow = $company->getActiveIvrFlow();

    expect($flow)->not->toBeNull()
        ->and($flow['greeting'])->toContain('currently closed')
        ->and($flow['no_input_action'])->toBe('voicemail');

    Carbon::setTestNow();
});

test('getActiveIvrFlow supports legacy single-flow config', function () {
    $company = Company::factory()->create([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'greeting' => 'Welcome to our company.',
                'options' => [
                    '1' => ['action' => 'route_agent', 'label' => 'Talk to us'],
                ],
            ],
        ],
    ]);

    $flow = $company->getActiveIvrFlow();

    expect($flow)->not->toBeNull()
        ->and($flow['greeting'])->toBe('Welcome to our company.')
        ->and($flow['options'])->toHaveKey('1')
        ->and($flow['no_input_action'])->toBe('voicemail');
});

test('withIvr factory state accepts custom options', function () {
    $company = Company::factory()->withIvr(
        businessHoursOptions: ['1' => ['action' => 'route_agent', 'label' => 'Custom BH']],
        afterHoursOptions: ['1' => ['action' => 'voicemail', 'label' => 'Custom AH']],
    )->create();

    $settings = $company->settings;

    expect($settings['ivr']['enabled'])->toBeTrue()
        ->and($settings['ivr']['business_hours']['options']['1']['label'])->toBe('Custom BH')
        ->and($settings['ivr']['after_hours']['options']['1']['label'])->toBe('Custom AH');
});
