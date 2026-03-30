<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\TwilioAccount;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->agent = Agent::factory()->forCompany($this->company)->available()->create();
    $this->contact = Contact::factory()->forCompany($this->company)->create();
});

it('updates call status from twilio webhook', function () {
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'twilio_call_sid' => 'CA_test_123',
        'status' => 'ringing',
    ]);

    $response = $this->post(route('prospector.webhook.twilio.voice.status'), [
        'CallSid' => 'CA_test_123',
        'CallStatus' => 'completed',
        'CallDuration' => '45',
    ]);

    $response->assertSuccessful();

    $call->refresh();
    expect($call->status)->toBe('completed');
    expect($call->duration_seconds)->toBe(45);
    expect($call->ended_at)->not->toBeNull();
});

it('maps twilio no-answer status correctly', function () {
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'twilio_call_sid' => 'CA_no_answer',
        'status' => 'ringing',
    ]);

    $this->post(route('prospector.webhook.twilio.voice.status'), [
        'CallSid' => 'CA_no_answer',
        'CallStatus' => 'no-answer',
    ]);

    $call->refresh();
    expect($call->status)->toBe('no_answer');
});

it('returns 200 for unknown call sid', function () {
    $response = $this->post(route('prospector.webhook.twilio.voice.status'), [
        'CallSid' => 'CA_nonexistent',
        'CallStatus' => 'completed',
    ]);

    $response->assertSuccessful();
});

it('handles AMD callback for human detection', function () {
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'twilio_call_sid' => 'CA_amd_human',
        'status' => 'ringing',
        'amd_result' => null,
    ]);

    $response = $this->post(route('prospector.webhook.twilio.amd'), [
        'CallSid' => 'CA_amd_human',
        'AnsweredBy' => 'human',
    ]);

    $response->assertSuccessful();

    $call->refresh();
    expect($call->amd_result)->toBe('human');
});

it('handles AMD callback for machine detection', function () {
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'twilio_call_sid' => 'CA_amd_machine',
        'status' => 'ringing',
        'amd_result' => null,
    ]);

    $response = $this->post(route('prospector.webhook.twilio.amd'), [
        'CallSid' => 'CA_amd_machine',
        'AnsweredBy' => 'machine_start',
    ]);

    $response->assertSuccessful();

    $call->refresh();
    expect($call->amd_result)->toBe('machine');
    expect($call->status)->toBe('voicemail');
});

it('returns 200 for conference status events', function () {
    $response = $this->post(route('prospector.webhook.twilio.conference.status'), [
        'ConferenceSid' => 'CF_test',
        'StatusCallbackEvent' => 'participant-join',
        'FriendlyName' => 'prospector-session-test',
    ]);

    $response->assertSuccessful();
});

it('returns 200 for incoming SMS', function () {
    $response = $this->post(route('prospector.webhook.twilio.sms.incoming'), [
        'From' => '+13035551234',
        'To' => '+17205559999',
        'Body' => 'Stop calling me',
        'MessageSid' => 'SM_test_123',
    ]);

    $response->assertSuccessful();
});

it('returns 200 for SMS status callback', function () {
    $response = $this->post(route('prospector.webhook.twilio.sms.status'), [
        'MessageSid' => 'SM_test_123',
        'MessageStatus' => 'delivered',
        'To' => '+13035551234',
    ]);

    $response->assertSuccessful();
});
