<?php

use App\Models\Deal;
use App\Models\RoiCalculation;
use App\Models\User;

it('creates roi calculation with factory', function () {
    $roi = RoiCalculation::factory()->create();

    expect($roi)->toBeInstanceOf(RoiCalculation::class)
        ->and($roi->deal_id)->not->toBeNull()
        ->and($roi->agent_id)->not->toBeNull()
        ->and($roi->property_value)->not->toBeNull()
        ->and($roi->scope)->toBeIn(['interior', 'exterior', 'both'])
        ->and($roi->intent)->toBeIn(['selling', 'buying']);
});

it('belongs to a deal', function () {
    $roi = RoiCalculation::factory()->create();

    expect($roi->deal)->toBeInstanceOf(Deal::class);
});

it('belongs to an agent', function () {
    $roi = RoiCalculation::factory()->create();

    expect($roi->agent)->toBeInstanceOf(User::class);
});

it('generates public token', function () {
    $roi = RoiCalculation::factory()->create();

    $token = $roi->generatePublicToken(30);

    expect($token)->toHaveLength(64)
        ->and($roi->public_token)->toBe($token)
        ->and($roi->public_token_expires_at)->not->toBeNull()
        ->and($roi->isPublicTokenValid())->toBeTrue();
});

it('scopes by public token', function () {
    $roi = RoiCalculation::factory()->withPublicToken()->create();

    $found = RoiCalculation::byPublicToken($roi->public_token)->first();

    expect($found->id)->toBe($roi->id);
});

it('deal has roi calculations relationship', function () {
    $deal = Deal::factory()->withAgentRoi()->create();
    RoiCalculation::factory()->create(['deal_id' => $deal->id]);

    expect($deal->roiCalculations)->toHaveCount(1)
        ->and($deal->roiCalculation)->toBeInstanceOf(RoiCalculation::class);
});

it('deal factory withAgentRoi sets all fields', function () {
    $deal = Deal::factory()->withAgentRoi('selling', 'both')->create();

    expect($deal->agent_intent)->toBe('selling')
        ->and($deal->listing_value)->not->toBeNull()
        ->and($deal->listing_bedrooms)->not->toBeNull()
        ->and($deal->listing_sqft)->not->toBeNull()
        ->and($deal->roi_scope)->toBe('both')
        ->and($deal->agent_id)->not->toBeNull();
});
