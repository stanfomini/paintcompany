<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\PhoneNumberService;

beforeEach(function () {
    $this->service = new PhoneNumberService;
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ─── getWebhookUrls ───────────────────────────────────

it('returns correct webhook urls for the application', function () {
    $urls = $this->service->getWebhookUrls();

    expect($urls)->toBeArray()
        ->and($urls)->toHaveKeys(['voiceUrl', 'voiceMethod', 'smsUrl', 'smsMethod', 'statusCallback', 'statusCallbackMethod'])
        ->and($urls['voiceUrl'])->toContain('/prospector/webhook/twilio/voice/inbound')
        ->and($urls['smsUrl'])->toContain('/prospector/webhook/twilio/sms/incoming')
        ->and($urls['statusCallback'])->toContain('/prospector/webhook/twilio/voice/status')
        ->and($urls['voiceMethod'])->toBe('POST')
        ->and($urls['smsMethod'])->toBe('POST')
        ->and($urls['statusCallbackMethod'])->toBe('POST');
});

// ─── configureWebhooks ────────────────────────────────

it('skips inactive numbers when configuring webhooks', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->inactive()->create([
        'company_id' => $this->company->id,
    ]);

    $result = $this->service->configureWebhooks($number);

    expect($result['status'])->toBe('skipped')
        ->and($result)->toHaveKey('error');
});

it('skips numbers without twilio sid when configuring webhooks', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
    ]);

    // Manually clear the twilio_sid on the model (DB has NOT NULL constraint)
    $number->twilio_sid = null;

    $result = $this->service->configureWebhooks($number);

    expect($result['status'])->toBe('skipped')
        ->and($result['error'])->toBe('No Twilio SID or number inactive');
});

it('returns error when number has no twilio account', function () {
    $orphanAccount = TwilioAccount::factory()->create();
    $number = PhoneNumber::factory()->forAccount($orphanAccount)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    // Delete the account so the relationship returns null after load()
    $orphanAccount->delete();

    $result = $this->service->configureWebhooks($number);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('No Twilio account associated');
});

// ─── configureWebhooksForCompany ──────────────────────

it('returns dry run results for all company numbers', function () {
    PhoneNumber::factory()->count(3)->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $results = $this->service->configureWebhooksForCompany($this->company, dryRun: true);

    expect($results)->toHaveCount(3)
        ->and($results[0])->toHaveKey('phone_number')
        ->and($results[0])->toHaveKey('status')
        ->and($results[0]['status'])->toBe('would_configure');
});

it('excludes inactive numbers from company webhook config', function () {
    PhoneNumber::factory()->count(2)->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    PhoneNumber::factory()->forAccount($this->account)->inactive()->create([
        'company_id' => $this->company->id,
    ]);

    $results = $this->service->configureWebhooksForCompany($this->company, dryRun: true);

    expect($results)->toHaveCount(2);
});

it('only includes numbers belonging to the specified company', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();

    PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $otherCompany->id,
        'is_active' => true,
    ]);

    $results = $this->service->configureWebhooksForCompany($this->company, dryRun: true);

    expect($results)->toHaveCount(1);
});

it('returns empty array when company has no numbers', function () {
    $results = $this->service->configureWebhooksForCompany($this->company, dryRun: true);

    expect($results)->toBeArray()->toBeEmpty();
});

// ─── auto-configure on assignment ─────────────────────

it('auto-configures webhooks when assigning a number to a company', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create();

    // configureWebhooks will fail (no real Twilio API) but should not throw —
    // the method catches exceptions and logs a warning
    $result = $this->service->assignToCompany($number, $this->company, 'local_presence');

    expect($result->company_id)->toBe($this->company->id)
        ->and($result->role)->toBe('local_presence');
});

// ─── integration: UI ─────────────────────────────────

it('phone numbers page has configure webhooks button', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);

    // Create a company with an assigned number so the pool view renders
    PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'role' => 'local_presence',
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.phone-numbers')
        ->assertSee('Configure Webhooks')
        ->assertStatus(200);
});
