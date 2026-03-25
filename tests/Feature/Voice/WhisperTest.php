<?php

use App\Models\CallLog;
use App\Models\Customer;
use App\Services\TwilioService;

beforeEach(function () {
    $this->twilioMock = $this->partialMock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true)->byDefault();
    });
});

describe('whisper webhook', function () {
    it('returns valid twiml with say element', function () {
        $callLog = CallLog::factory()->create(['from_number' => '+13035551234']);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $xml = simplexml_load_string($response->getContent());
        expect($xml)->not->toBeFalse()
            ->and($response->getContent())->toContain('<Say')
            ->and($response->getContent())->toContain('Polly.Joanna');
    });

    it('includes customer name when customer is identified', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);

        $callLog = CallLog::factory()->create([
            'customer_id' => $customer->id,
            'from_number' => '+13035551234',
        ]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('John Smith');
    });

    it('falls back to phone number when no customer', function () {
        $callLog = CallLog::factory()->create([
            'customer_id' => null,
            'from_number' => '+13035551234',
        ]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('+13035551234');
    });

    it('identifies transferred calls', function () {
        $callLog = CallLog::factory()->create([
            'metadata' => ['transferred' => true],
        ]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Transferred call');
    });

    it('identifies extension calls', function () {
        $callLog = CallLog::factory()->create([
            'metadata' => ['extension_dialed' => '101'],
        ]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Extension call');
    });

    it('identifies ivr-routed calls with group name', function () {
        $callLog = CallLog::factory()->create([
            'metadata' => ['ivr_option' => '1', 'call_group_name' => 'Estimates'],
        ]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Inbound call, Estimates');
    });

    it('defaults to inbound call when no metadata', function () {
        $callLog = CallLog::factory()->create(['metadata' => null]);

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Inbound call');
    });

    it('returns hangup for unknown call log', function () {
        $response = $this->post('/webhooks/twilio/voice/whisper/nonexistentulid');

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');
    });

    it('rejects invalid twilio signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $callLog = CallLog::factory()->create();

        $response = $this->post("/webhooks/twilio/voice/whisper/{$callLog->ulid}");

        $response->assertForbidden();
    });
});
