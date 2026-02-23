<?php

use App\Jobs\ProcessInboundEmailJob;
use Illuminate\Support\Facades\Queue;

describe('Email Webhook Controller', function () {
    it('returns 200 for POST to inbound webhook', function () {
        Queue::fake();

        $response = $this->postJson('/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'Webhook Test',
            'body-plain' => 'Test body',
            'timestamp' => (string) time(),
            'token' => 'test-token',
            'signature' => 'test-sig',
        ]);

        $response->assertSuccessful();
        $response->assertJson(['status' => 'queued']);
    });

    it('dispatches ProcessInboundEmailJob', function () {
        Queue::fake();

        $this->postJson('/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'Dispatch Test',
            'body-plain' => 'Dispatch body',
            'timestamp' => (string) time(),
            'token' => 'dispatch-token',
            'signature' => 'dispatch-sig',
        ]);

        Queue::assertPushed(ProcessInboundEmailJob::class, function ($job) {
            return $job->payload['subject'] === 'Dispatch Test';
        });
    });
});
