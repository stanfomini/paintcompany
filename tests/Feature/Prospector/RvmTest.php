<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\RvmDrop;
use App\Models\Prospector\RvmTemplate;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\RvmService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();

    $this->mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->company->update(['main_phone_number_id' => $this->mainNumber->id]);

    $this->contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $this->template = RvmTemplate::factory()->forCompany($this->company)->create();
    $this->service = app(RvmService::class);
});

// ── Template Model Tests ──

it('creates RVM template with factory', function () {
    $template = RvmTemplate::factory()->create();

    expect($template)->toBeInstanceOf(RvmTemplate::class);
    expect($template->name)->not->toBeNull();
    expect($template->audio_path)->not->toBeNull();
    expect($template->is_active)->toBeTrue();
});

it('has correct template relations', function () {
    expect($this->template->company->id)->toBe($this->company->id);
    expect($this->template->createdBy)->not->toBeNull();
});

it('filters active templates', function () {
    RvmTemplate::factory()->forCompany($this->company)->create(['is_active' => true]);
    RvmTemplate::factory()->forCompany($this->company)->inactive()->create();

    // +1 from beforeEach
    expect(RvmTemplate::active()->forCompany($this->company->id)->count())->toBe(2);
});

it('filters templates by company', function () {
    $other = Company::factory()->forAccount($this->account)->create();
    RvmTemplate::factory()->forCompany($other)->create();

    expect(RvmTemplate::forCompany($this->company->id)->count())->toBe(1);
});

// ── Drop Model Tests ──

it('creates RVM drop with factory', function () {
    $drop = RvmDrop::factory()->create();

    expect($drop)->toBeInstanceOf(RvmDrop::class);
    expect($drop->status)->toBe('queued');
});

it('has correct drop relations', function () {
    $drop = RvmDrop::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'template_id' => $this->template->id,
    ]);

    expect($drop->company->id)->toBe($this->company->id);
    expect($drop->contact->id)->toBe($this->contact->id);
    expect($drop->template->id)->toBe($this->template->id);
});

it('transitions drop to sent', function () {
    $drop = RvmDrop::factory()->create();
    $drop->markSent('CA123abc');

    expect($drop->status)->toBe('sent');
    expect($drop->provider_id)->toBe('CA123abc');
    expect($drop->sent_at)->not->toBeNull();
});

it('transitions drop to delivered', function () {
    $drop = RvmDrop::factory()->sent()->create();
    $drop->markDelivered();

    expect($drop->status)->toBe('delivered');
    expect($drop->delivered_at)->not->toBeNull();
});

it('transitions drop to failed', function () {
    $drop = RvmDrop::factory()->create();
    $drop->markFailed('Connection refused');

    expect($drop->status)->toBe('failed');
    expect($drop->error)->toBe('Connection refused');
});

// ── Service Tests ──

it('selects default method from company settings', function () {
    expect($this->service->getDefaultMethod($this->company))->toBe('twilio_amd');

    $this->company->update(['settings' => ['rvm_method' => 'slybroadcast']]);
    expect($this->service->getDefaultMethod($this->company))->toBe('slybroadcast');
});

it('creates drop record and initiates via Twilio AMD', function () {
    // Twilio client will fail in test env, but drop record should be created
    try {
        $drop = $this->service->drop($this->company, $this->contact, $this->template, 'twilio_amd');
    } catch (\Exception $e) {
        // Expected: Twilio client will fail in test env
    }

    $drop = RvmDrop::where('contact_id', $this->contact->id)->first();
    expect($drop)->not->toBeNull();
    expect($drop->method)->toBe('twilio_amd');
    expect($drop->from_number)->toBe($this->mainNumber->phone_number);
    expect($drop->to_number)->toBe($this->contact->phone);
});

it('throws when no main phone number configured', function () {
    $company = Company::factory()->forAccount($this->account)->create([
        'main_phone_number_id' => null,
    ]);

    expect(fn () => $this->service->drop($company, $this->contact, $this->template))
        ->toThrow(RuntimeException::class);
});

it('handles callback and updates drop status to delivered', function () {
    $drop = RvmDrop::factory()->sent()->create([
        'company_id' => $this->company->id,
        'provider_id' => 'CA-test-123',
    ]);

    $this->service->handleCallback('CA-test-123', 'delivered');

    $drop->refresh();
    expect($drop->status)->toBe('delivered');
});

it('handles callback and updates drop status to failed', function () {
    $drop = RvmDrop::factory()->sent()->create([
        'company_id' => $this->company->id,
        'provider_id' => 'CA-test-456',
    ]);

    $this->service->handleCallback('CA-test-456', 'failed');

    $drop->refresh();
    expect($drop->status)->toBe('failed');
});
