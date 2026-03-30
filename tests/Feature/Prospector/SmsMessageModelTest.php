<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\SmsMessage;
use App\Models\Prospector\TwilioAccount;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->agent = Agent::factory()->forCompany($this->company)->create();
    $this->contact = Contact::factory()->forCompany($this->company)->create();
});

it('creates an SMS message with factory', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($sms)->toBeInstanceOf(SmsMessage::class);
    expect($sms->id)->toBeGreaterThan(0);
    expect($sms->company_id)->toBe($this->company->id);
    expect($sms->contact_id)->toBe($this->contact->id);
    expect($sms->agent_id)->toBe($this->agent->id);
});

it('has company relation', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($sms->company)->toBeInstanceOf(Company::class);
    expect($sms->company->id)->toBe($this->company->id);
});

it('has contact relation', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($sms->contact)->toBeInstanceOf(Contact::class);
    expect($sms->contact->id)->toBe($this->contact->id);
});

it('has agent relation', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($sms->agent)->toBeInstanceOf(Agent::class);
    expect($sms->agent->id)->toBe($this->agent->id);
});

it('scopes by company', function () {
    SmsMessage::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    $otherCompany = Company::factory()->forAccount($this->account)->create();
    SmsMessage::factory()->count(2)->create([
        'company_id' => $otherCompany->id,
    ]);

    expect(SmsMessage::forCompany($this->company->id)->count())->toBe(3);
});

it('scopes by contact', function () {
    SmsMessage::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    $otherContact = Contact::factory()->forCompany($this->company)->create();
    SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $otherContact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect(SmsMessage::forContact($this->contact->id)->count())->toBe(2);
});

it('scopes inbound and outbound', function () {
    SmsMessage::factory()->outbound()->count(3)->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    SmsMessage::factory()->inbound()->count(2)->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect(SmsMessage::inbound()->count())->toBe(2);
    expect(SmsMessage::outbound()->count())->toBe(3);
});

it('casts media_urls to array', function () {
    $sms = SmsMessage::factory()->withMedia()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($sms->media_urls)->toBeArray();
    expect($sms->media_urls)->toHaveCount(1);
});

it('supports null media_urls', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
        'media_urls' => null,
    ]);

    expect($sms->media_urls)->toBeNull();
});

it('uses factory states correctly', function () {
    $delivered = SmsMessage::factory()->delivered()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    $failed = SmsMessage::factory()->failed()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    $inbound = SmsMessage::factory()->inbound()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
    ]);

    expect($delivered->status)->toBe('delivered');
    expect($failed->status)->toBe('failed');
    expect($inbound->direction)->toBe('inbound');
    expect($inbound->status)->toBe('received');
});
