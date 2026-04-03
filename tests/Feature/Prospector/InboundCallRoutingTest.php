<?php

use App\Events\Prospector\InboundCallRinging;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->phoneNumber = PhoneNumber::factory()
        ->forAccount($this->account)
        ->create([
            'company_id' => $this->company->id,
            'phone_number' => '+17205550100',
            'role' => 'main',
        ]);
    $this->inboundUrl = route('prospector.webhook.twilio.voice.inbound');
});

// ─── Inbound Call Routing ────────────────────────────────

it('creates contact if not exists on inbound call', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_test_1',
    ])->assertSuccessful();

    $contact = Contact::where('company_id', $this->company->id)
        ->where('phone', '+17205559999')
        ->first();

    expect($contact)->not->toBeNull();
    expect($contact->first_name)->toBe('Unknown');
    expect($contact->source)->toBe('inbound_call');
});

it('routes inbound call to available agent', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_route_1',
    ]);

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/xml');

    $xml = $response->getContent();
    expect($xml)->toContain('<Conference');
    expect($xml)->toContain('Please hold while we connect you to an agent');
});

it('creates inbound call record with direction inbound', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_record_1',
    ]);

    $call = Call::where('twilio_call_sid', 'CA_inbound_record_1')->first();

    expect($call)->not->toBeNull();
    expect($call->direction)->toBe('inbound');
    expect($call->status)->toBe('holding');
    expect($call->company_id)->toBe($this->company->id);
    expect($call->agent_id)->toBe($agent->id);
    expect($call->from_number)->toBe('+17205559999');
    expect($call->to_number)->toBe('+17205550100');
});

it('broadcasts InboundCallRinging on inbound call', function () {
    Event::fake([InboundCallRinging::class]);
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_broadcast_1',
    ]);

    Event::assertDispatched(InboundCallRinging::class, function ($event) use ($agent) {
        return in_array($agent->id, $event->agentIds)
            && $event->call->direction === 'inbound';
    });
});

it('goes to voicemail if no agents available', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    // No agents created — none available
    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_noagent_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('All of our agents are currently busy');
    expect($xml)->toContain('<Record');
});

it('goes to voicemail for unknown phone number', function () {
    $response = $this->post($this->inboundUrl, [
        'To' => '+19995550000', // Unknown number
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_unknown_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('no one is available');
    expect($xml)->toContain('<Record');
});

it('uses existing contact for known caller', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $existingContact = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205559999',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_existing_1',
    ]);

    $call = Call::where('twilio_call_sid', 'CA_inbound_existing_1')->first();
    expect($call->contact_id)->toBe($existingContact->id);

    // Should not create a new contact
    expect(Contact::where('company_id', $this->company->id)->count())->toBe(1);
});

it('skips busy agents and goes to voicemail', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    // Agent exists but is on a call (not available)
    Agent::factory()->forCompany($this->company)->create(['status' => 'on_call']);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_inbound_busy_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('All of our agents are currently busy');
});

// ─── IVR (Legacy Flat Format) ──────────────────────────────

it('renders IVR greeting when IVR is enabled', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'greeting' => 'Press 1 for sales. Press 2 for support.',
                'options' => [
                    '1' => ['action' => 'route_agent', 'label' => 'Sales'],
                    '2' => ['action' => 'voicemail', 'label' => 'Support'],
                ],
            ],
        ],
    ]);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_ivr_greeting_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('<Gather');
    expect($xml)->toContain('Press 1 for sales');
    expect($xml)->toContain('numDigits="1"');
});

it('IVR routes to agent on digit 1', function () {
    Event::fake([InboundCallRinging::class]);
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'greeting' => 'Press 1 for sales.',
                'options' => [
                    '1' => ['action' => 'route_agent', 'label' => 'Sales'],
                    '2' => ['action' => 'voicemail', 'label' => 'Support'],
                ],
            ],
        ],
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205559999',
    ]);

    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '1',
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'CallSid' => 'CA_ivr_route_1',
        'From' => '+17205559999',
        'To' => '+17205550100',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('<Conference');
    expect($xml)->toContain('Please hold');
});

it('IVR routes to voicemail on digit 2', function () {
    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'greeting' => 'Press 1 for sales.',
                'options' => [
                    '1' => ['action' => 'route_agent', 'label' => 'Sales'],
                    '2' => ['action' => 'voicemail', 'label' => 'Support'],
                ],
            ],
        ],
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '2',
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'CallSid' => 'CA_ivr_vm_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('no one is available');
    expect($xml)->toContain('<Record');
});

it('IVR replays on invalid digit', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'greeting' => 'Press 1 for sales.',
                'options' => [
                    '1' => ['action' => 'route_agent', 'label' => 'Sales'],
                ],
            ],
        ],
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '9', // Invalid digit
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'CallSid' => 'CA_ivr_invalid_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    // Should replay the IVR
    expect($xml)->toContain('<Gather');
    expect($xml)->toContain('Press 1 for sales');
});

it('IVR falls back to voicemail for invalid company', function () {
    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '1',
        'company_id' => 99999,
        'contact_id' => 1,
        'CallSid' => 'CA_ivr_badco_1',
    ]);

    $response->assertSuccessful();

    $xml = $response->getContent();
    expect($xml)->toContain('no one is available');
});

it('does not use IVR when disabled', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent = Agent::factory()->forCompany($this->company)->available()->create();

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => false,
                'greeting' => 'Press 1 for sales.',
                'options' => [],
            ],
        ],
    ]);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100',
        'From' => '+17205559999',
        'CallSid' => 'CA_ivr_disabled_1',
    ]);

    $xml = $response->getContent();
    // Should route directly, not show IVR
    expect($xml)->not->toContain('<Gather');
    expect($xml)->toContain('<Conference');
});

// ─── Dual-Flow IVR (Business Hours / After Hours) ──────────

it('uses business hours IVR flow during business hours', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'business_hours' => [
                    'greeting' => 'Welcome during business hours. Press 1 for sales.',
                    'options' => ['1' => ['action' => 'route_agent', 'label' => 'Sales']],
                    'no_input_action' => 'route_agent',
                ],
                'after_hours' => [
                    'greeting' => 'We are closed. Press 1 for voicemail.',
                    'options' => ['1' => ['action' => 'voicemail', 'label' => 'Voicemail']],
                    'no_input_action' => 'voicemail',
                ],
            ],
        ],
    ]);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100', 'From' => '+17205559999', 'CallSid' => 'CA_bh_during_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('Welcome during business hours');
    expect($xml)->not->toContain('We are closed');
});

it('uses after hours IVR flow outside business hours', function () {
    $this->travelTo(Carbon::parse('2026-04-01 22:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'business_hours' => [
                    'greeting' => 'Welcome during business hours.',
                    'options' => ['1' => ['action' => 'route_agent', 'label' => 'Sales']],
                    'no_input_action' => 'route_agent',
                ],
                'after_hours' => [
                    'greeting' => 'We are closed. Press 1 for voicemail.',
                    'options' => ['1' => ['action' => 'voicemail', 'label' => 'Voicemail']],
                    'no_input_action' => 'voicemail',
                ],
            ],
        ],
    ]);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100', 'From' => '+17205559999', 'CallSid' => 'CA_bh_after_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('We are closed');
    expect($xml)->not->toContain('Welcome during business hours');
});

it('goes to voicemail after hours when IVR disabled', function () {
    $this->travelTo(Carbon::parse('2026-04-01 22:00:00', 'America/Denver'));

    Agent::factory()->forCompany($this->company)->available()->create();
    $this->company->update(['settings' => ['ivr' => ['enabled' => false]]]);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100', 'From' => '+17205559999', 'CallSid' => 'CA_noivr_after_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('currently closed');
    expect($xml)->not->toContain('<Conference');
});

it('IVR plays info message and re-gathers on play_info action', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'business_hours' => [
                    'greeting' => 'Press 2 for info.',
                    'options' => ['2' => ['action' => 'play_info', 'label' => 'Info', 'message' => 'Our hours are 8am to 5pm.']],
                    'no_input_action' => 'voicemail',
                ],
                'after_hours' => ['greeting' => 'Closed.', 'options' => [], 'no_input_action' => 'voicemail'],
            ],
        ],
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '2', 'company_id' => $this->company->id, 'contact_id' => $contact->id, 'CallSid' => 'CA_playinfo_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('Our hours are 8am to 5pm');
    expect($xml)->toContain('<Gather');
});

it('IVR forwards call on forward action', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $this->company->update([
        'settings' => [
            'ivr' => [
                'enabled' => true,
                'business_hours' => [
                    'greeting' => 'Press 3 for office.',
                    'options' => ['3' => ['action' => 'forward', 'label' => 'Office', 'forward_to' => '+13035551234']],
                    'no_input_action' => 'voicemail',
                ],
                'after_hours' => ['greeting' => 'Closed.', 'options' => [], 'no_input_action' => 'voicemail'],
            ],
        ],
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $response = $this->post(route('prospector.webhook.twilio.voice.ivr-response'), [
        'Digits' => '3', 'company_id' => $this->company->id, 'contact_id' => $contact->id, 'CallSid' => 'CA_forward_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('+13035551234');
});

it('finds agents via pivot table not just FK', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $otherCompany = Company::factory()->create();
    $agent = Agent::factory()->create(['company_id' => $otherCompany->id, 'status' => 'available']);
    $agent->companies()->attach($this->company->id);

    $response = $this->post($this->inboundUrl, [
        'To' => '+17205550100', 'From' => '+17205559999', 'CallSid' => 'CA_pivot_agent_1',
    ]);

    $xml = $response->getContent();
    expect($xml)->toContain('<Conference');
});

it('broadcasts InboundCallRinging to all available agents', function () {
    Event::fake([InboundCallRinging::class]);
    $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'America/Denver'));

    $agent1 = Agent::factory()->forCompany($this->company)->available()->create();
    $agent2 = Agent::factory()->forCompany($this->company)->available()->create();
    $busyAgent = Agent::factory()->forCompany($this->company)->create(['status' => 'on_call']);

    $this->post($this->inboundUrl, [
        'To' => '+17205550100', 'From' => '+17205559999', 'CallSid' => 'CA_multi_ring_1',
    ]);

    Event::assertDispatched(InboundCallRinging::class, function ($event) use ($agent1, $agent2, $busyAgent) {
        return in_array($agent1->id, $event->agentIds)
            && in_array($agent2->id, $event->agentIds)
            && ! in_array($busyAgent->id, $event->agentIds);
    });
});
