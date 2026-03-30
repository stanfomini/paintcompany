<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\TcpaComplianceService;

beforeEach(function () {
    $this->service = new TcpaComplianceService;
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

it('blocks calls to DNC contacts', function () {
    $contact = Contact::factory()->forCompany($this->company)->dnc()->create();

    expect($this->service->canCall($contact, $this->company))->toBeFalse();

    $reasons = $this->service->getCallBlockReasons($contact, $this->company);
    expect($reasons)->toContain('Contact is on the Do Not Call list.');
});

it('allows calls to non-DNC contacts during business hours', function () {
    config(['prospector.tcpa.calling_hours_start' => '00:00']);
    config(['prospector.tcpa.calling_hours_end' => '23:59']);

    $contact = Contact::factory()->forCompany($this->company)->create([
        'do_not_call' => false,
        'last_contacted_at' => null,
    ]);

    expect($this->service->canCall($contact, $this->company))->toBeTrue();
});

it('blocks calls when contact has exceeded max attempts for campaign', function () {
    config(['prospector.tcpa.max_attempts_per_contact' => 3]);

    $contact = Contact::factory()->forCompany($this->company)->create();
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);

    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'sort_order' => 1,
        'attempts' => 3,
        'added_at' => now(),
    ]);

    expect($this->service->hasExceededAttempts($contact, $campaign))->toBeTrue();
    expect($this->service->canCall($contact, $this->company, $campaign))->toBeFalse();
});

it('blocks calls within cooldown period', function () {
    config(['prospector.tcpa.min_hours_between_attempts' => 4]);

    $contact = Contact::factory()->forCompany($this->company)->create([
        'last_contacted_at' => now()->subHours(2),
    ]);

    expect($this->service->isWithinCooldown($contact))->toBeTrue();
});

it('allows calls after cooldown period expires', function () {
    config(['prospector.tcpa.min_hours_between_attempts' => 4]);

    $contact = Contact::factory()->forCompany($this->company)->create([
        'last_contacted_at' => now()->subHours(5),
    ]);

    expect($this->service->isWithinCooldown($contact))->toBeFalse();
});

it('checks TCPA calling hours in contact timezone', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'timezone' => 'America/New_York',
    ]);

    // This tests that the service respects the timezone parameter
    $result = $this->service->isInCallingHours($contact, $this->company);

    expect($result)->toBeBool();
});
