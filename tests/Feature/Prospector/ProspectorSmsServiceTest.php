<?php

use App\Events\Prospector\SmsReceived;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\SmsMessage;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ProspectorSmsService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();

    $this->mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->company->update(['main_phone_number_id' => $this->mainNumber->id]);

    $this->agent = Agent::factory()->forCompany($this->company)->create();
    $this->contact = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+13035551234',
        'total_sms' => 0,
    ]);

    $this->service = app(ProspectorSmsService::class);
});

it('creates an SMS record when sending', function () {
    // Twilio will fail in test env, but record is created first
    $smsCount = SmsMessage::count();

    try {
        $this->service->sendSms($this->company, $this->contact, 'Hello from test');
    } catch (\Exception $e) {
        // Expected: Twilio will fail in test environment
    }

    // An SmsMessage record should have been created (status may be 'queued' or 'failed')
    expect(SmsMessage::count())->toBeGreaterThanOrEqual($smsCount + 1);

    $sms = SmsMessage::latest()->first();
    expect($sms->company_id)->toBe($this->company->id);
    expect($sms->contact_id)->toBe($this->contact->id);
    expect($sms->direction)->toBe('outbound');
    expect($sms->body)->toBe('Hello from test');
    expect($sms->from_number)->toBe($this->mainNumber->phone_number);
    expect($sms->to_number)->toBe($this->contact->phone);
});

it('processes incoming SMS and matches contact', function () {
    Event::fake([SmsReceived::class]);

    $sms = $this->service->handleIncoming(
        from: '+13035551234',
        to: $this->mainNumber->phone_number,
        body: 'Yes I am interested',
        twilioSid: 'SM'.fake()->regexify('[a-f0-9]{32}'),
    );

    expect($sms)->toBeInstanceOf(SmsMessage::class);
    expect($sms->direction)->toBe('inbound');
    expect($sms->status)->toBe('received');
    expect($sms->contact_id)->toBe($this->contact->id);
    expect($sms->company_id)->toBe($this->company->id);
    expect($sms->body)->toBe('Yes I am interested');

    $this->contact->refresh();
    expect($this->contact->total_sms)->toBe(1);

    Event::assertDispatched(SmsReceived::class);
});

it('creates incoming SMS with null contact when no match', function () {
    Event::fake([SmsReceived::class]);

    $sms = $this->service->handleIncoming(
        from: '+15551239999',
        to: $this->mainNumber->phone_number,
        body: 'Who is this?',
    );

    expect($sms->contact_id)->toBeNull();
    expect($sms->direction)->toBe('inbound');
});

it('throws when incoming SMS to unknown number', function () {
    $this->service->handleIncoming(
        from: '+15551234567',
        to: '+19999999999',
        body: 'Hello',
    );
})->throws(RuntimeException::class);

it('retrieves conversation thread for a contact', function () {
    SmsMessage::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'direction' => 'outbound',
    ]);

    SmsMessage::factory()->count(2)->inbound()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
    ]);

    $conversation = $this->service->getConversation($this->contact);

    expect($conversation)->toHaveCount(5);
});

it('marks SMS as delivered by Twilio SID', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
        'status' => 'sent',
        'twilio_sid' => 'SM_test_delivery_123',
    ]);

    $this->service->markDelivered('SM_test_delivery_123');

    $sms->refresh();
    expect($sms->status)->toBe('delivered');
});

it('marks SMS as failed by Twilio SID', function () {
    $sms = SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'agent_id' => $this->agent->id,
        'status' => 'sent',
        'twilio_sid' => 'SM_test_failure_456',
    ]);

    $this->service->markFailed('SM_test_failure_456', 'Undeliverable');

    $sms->refresh();
    expect($sms->status)->toBe('failed');
});

it('handles delivery callback for unknown SID gracefully', function () {
    // Should not throw
    $this->service->markDelivered('SM_nonexistent');
    $this->service->markFailed('SM_nonexistent');

    expect(true)->toBeTrue();
});

it('throws when company has no main phone number', function () {
    $companyNoPhone = Company::factory()->forAccount($this->account)->create();

    $contact = Contact::factory()->forCompany($companyNoPhone)->create();

    $this->service->sendSms($companyNoPhone, $contact, 'This should fail');
})->throws(RuntimeException::class);
