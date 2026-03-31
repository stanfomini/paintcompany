<?php

use App\Events\Prospector\DialerCallConnected;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use App\Services\Prospector\ConferenceService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->user = User::factory()->internal()->create();
    $this->agent = Agent::factory()->forCompany($this->company)->create([
        'user_id' => $this->user->id,
    ]);
});

// ──────────────────────────────────────
// Token Endpoint
// ──────────────────────────────────────

it('returns a voice token for authenticated internal user', function () {
    config([
        'prospector.twilio.api_key_sid' => 'SK_test_key_sid',
        'prospector.twilio.api_key_secret' => 'test_api_key_secret_value_32ch',
        'prospector.twilio.twiml_app_sid' => 'AP_test_twiml_app_sid',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('prospector.voice.token'));

    $response->assertOk()
        ->assertJsonStructure(['token']);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('returns 422 when no active company exists for token', function () {
    // Deactivate company
    $this->company->update(['is_active' => false]);

    $response = $this->actingAs($this->user)
        ->getJson(route('prospector.voice.token'));

    $response->assertStatus(422)
        ->assertJson(['error' => 'No active company configured.']);
});

it('requires authentication for voice token endpoint', function () {
    $response = $this->getJson(route('prospector.voice.token'));

    $response->assertUnauthorized();
});

// ──────────────────────────────────────
// TwiML Endpoint
// ──────────────────────────────────────

it('returns valid TwiML XML with Conference element', function () {
    $response = $this->post(route('prospector.webhook.twilio.voice.incoming'), [
        'conference' => 'prospector-session-test123',
    ]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    expect($xml->getName())->toBe('Response');
    expect((string) $xml->Dial->Conference)->toBe('prospector-session-test123');
});

it('returns hangup TwiML when no conference specified', function () {
    $response = $this->post(route('prospector.webhook.twilio.voice.incoming'), []);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    expect($xml->Say)->not->toBeEmpty();
    expect($xml->Hangup)->not->toBeEmpty();
});

it('returns client dial TwiML for client: target', function () {
    $response = $this->post(route('prospector.webhook.twilio.voice.incoming'), [
        'To' => 'client:agent-42',
    ]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    expect((string) $xml->Dial->Client)->toBe('agent-42');
});

// ──────────────────────────────────────
// Hold Participant
// ──────────────────────────────────────

it('has holdParticipant method on ConferenceService', function () {
    $service = new ConferenceService;

    expect(method_exists($service, 'holdParticipant'))->toBeTrue();

    $reflection = new ReflectionMethod($service, 'holdParticipant');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->getName())->toBe('conferenceSid');
    expect($params[1]->getName())->toBe('callSid');
    expect($params[2]->getName())->toBe('hold');
    expect($params[2]->getType()->getName())->toBe('bool');
});

// ──────────────────────────────────────
// DialerCallConnected Event
// ──────────────────────────────────────

it('includes conference_name in DialerCallConnected broadcast payload', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'twilio_conference_sid' => 'prospector-session-abc123',
    ]);

    $event = new DialerCallConnected($call, $this->agent->id);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKey('conference_name');
    expect($payload['conference_name'])->toBe('prospector-session-abc123');
    expect($payload)->toHaveKey('call_ulid');
    expect($payload)->toHaveKey('contact');
    expect($payload)->toHaveKey('amd_result');
});
