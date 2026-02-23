<?php

use App\Models\CompanySetting;

it('stores and retrieves commission defaults', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
        'estimator' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
        ],
    ]]);

    $settings->refresh();

    expect($settings->getCommissionDefaultsForRole('knocker'))->toHaveCount(1)
        ->and($settings->getCommissionDefaultsForRole('knocker')[0]['amount'])->toBe(25)
        ->and($settings->getCommissionDefaultsForRole('estimator')[0]['rate'])->toBe(0.06)
        ->and($settings->getCommissionDefaultsForRole('unknown'))->toBe([]);
});

it('retrieves tiered bracket defaults', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => [
        'tiers' => [
            'period' => 'monthly',
            'brackets' => [
                ['min' => 0, 'max' => 5000, 'rate' => 0.08],
                ['min' => 5001, 'max' => null, 'rate' => 0.12],
            ],
        ],
    ]]);

    $settings->refresh();
    $tiers = $settings->getCommissionTierDefaults();

    expect($tiers)->not->toBeNull()
        ->and($tiers['period'])->toBe('monthly')
        ->and($tiers['brackets'])->toHaveCount(2);
});

it('returns null tiers when none configured', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => ['knocker' => []]]);

    expect($settings->getCommissionTierDefaults())->toBeNull();
});

it('lists commission default roles excluding tiers', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => [
        'knocker' => [['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true]],
        'estimator' => [['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true]],
        'tiers' => ['period' => 'monthly', 'brackets' => []],
    ]]);

    $settings->refresh();
    $roles = $settings->getCommissionDefaultRoles();

    expect($roles)->toContain('knocker')
        ->toContain('estimator')
        ->not->toContain('tiers');
});
