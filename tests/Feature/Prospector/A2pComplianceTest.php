<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\MessagingService;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\TwilioComplianceService;

it('creates a messaging service record', function () {
    $account = TwilioAccount::factory()->create();
    $company = Company::factory()->create(['twilio_account_id' => $account->id]);

    $record = MessagingService::create([
        'twilio_account_id' => $account->id,
        'company_id' => $company->id,
        'friendly_name' => 'Test Messaging Service',
        'status' => 'pending',
    ]);

    expect($record->exists)->toBeTrue()
        ->and($record->ulid)->not()->toBeEmpty()
        ->and($record->friendly_name)->toBe('Test Messaging Service')
        ->and($record->twilioAccount->id)->toBe($account->id)
        ->and($record->company->id)->toBe($company->id);
});

it('tracks brand registration status', function () {
    $service = MessagingService::factory()->withBrand('APPROVED')->create();

    expect($service->isBrandApproved())->toBeTrue()
        ->and($service->getBrandStatusColor())->toBe('green');

    $pending = MessagingService::factory()->withBrand('PENDING')->create();
    expect($pending->isBrandApproved())->toBeFalse()
        ->and($pending->getBrandStatusColor())->toBe('amber');
});

it('tracks campaign registration status', function () {
    $service = MessagingService::factory()->withCampaign('VERIFIED')->create();

    expect($service->isCampaignVerified())->toBeTrue()
        ->and($service->getCampaignStatusColor())->toBe('green');

    $inProgress = MessagingService::factory()->withCampaign('IN_PROGRESS')->create();
    expect($inProgress->isCampaignVerified())->toBeFalse()
        ->and($inProgress->getCampaignStatusColor())->toBe('amber');
});

it('determines full compliance status', function () {
    $compliant = MessagingService::factory()->fullyCompliant()->create();
    expect($compliant->isFullyCompliant())->toBeTrue();

    $partial = MessagingService::factory()->withBrand('APPROVED')->create();
    expect($partial->isFullyCompliant())->toBeFalse();
});

it('compliance service returns messaging services for account', function () {
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->count(3)->create(['twilio_account_id' => $account->id]);

    $service = app(TwilioComplianceService::class);
    $services = $service->getMessagingServices($account);

    expect($services)->toHaveCount(3);
});

it('compliance service returns compliance summary', function () {
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->fullyCompliant()->create(['twilio_account_id' => $account->id]);
    MessagingService::factory()->withBrand('PENDING')->create(['twilio_account_id' => $account->id]);

    $service = app(TwilioComplianceService::class);
    $summary = $service->getComplianceSummary($account);

    expect($summary)->toHaveKeys(['total', 'fully_compliant', 'brand_approved', 'campaigns_verified'])
        ->and($summary['total'])->toBe(2)
        ->and($summary['fully_compliant'])->toBe(1)
        ->and($summary['brand_approved'])->toBe(1);
});

it('settings page shows compliance tab', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.settings')
        ->assertSee('A2P Compliance')
        ->assertStatus(200);
});

it('settings page compliance tab shows messaging services', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->fullyCompliant()->create([
        'twilio_account_id' => $account->id,
        'friendly_name' => 'My Messaging Service',
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.settings')
        ->set('activeTab', 'compliance')
        ->assertSee('My Messaging Service')
        ->assertSee('APPROVED')
        ->assertStatus(200);
});
