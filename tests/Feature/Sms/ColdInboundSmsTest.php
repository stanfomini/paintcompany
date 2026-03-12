<?php

use App\Events\NewThreadNotification;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
    });
});

describe('known customer with assigned contact', function () {
    it('creates a direct thread between customer and their assigned internal user', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5551234567']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hi, when is my paint job starting?',
            'From' => '+15551234567',
        ]);

        $response->assertStatus(200);

        // Should create a direct thread
        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($customerUser))->toBeTrue();
        expect($thread->hasParticipant($internal))->toBeTrue();

        // Should have the message
        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('Hi, when is my paint job starting?');
        expect($message->user_id)->toBe($customerUser->id);

        // Should create an SMS session
        $session = SmsSession::where('phone_number', '+15551234567')
            ->where('thread_id', $thread->id)
            ->first();
        expect($session)->not->toBeNull();
        expect($session->user_id)->toBe($customerUser->id);
    });

    it('reuses existing direct thread on second message', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5551234567']);

        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        // First message
        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'First message',
            'From' => '+15551234567',
        ]);

        $threadCount = ChatThread::where('type', ChatThread::TYPE_DIRECT)->count();

        // Second message (session expired, so it's cold again)
        SmsSession::query()->delete();

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Second message',
            'From' => '+15551234567',
        ]);

        // Should reuse the same thread, not create a new one
        expect(ChatThread::where('type', ChatThread::TYPE_DIRECT)->count())->toBe($threadCount);
    });
});

describe('known non-customer user', function () {
    it('creates a support thread for crew user', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $crewUser = User::factory()->crew()->create();
        $crewUser->crewProfile()->update(['phone' => '5559876543']);

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Running late to the job site',
            'From' => '+15559876543',
        ]);

        $response->assertStatus(200);

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->latest()
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->subject)->toContain('+15559876543');
        expect($thread->hasParticipant($crewUser))->toBeTrue();
        expect($thread->hasParticipant($internal))->toBeTrue();

        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('Running late to the job site');
        expect($message->user_id)->toBe($crewUser->id);
    });
});

describe('unknown phone number', function () {
    it('creates a support thread with guest message', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'I need a painting quote',
            'From' => '+15550001111',
        ]);

        $response->assertStatus(200);

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->latest()
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->subject)->toContain('+15550001111');
        expect($thread->hasParticipant($internal))->toBeTrue();

        // Message should be a guest message (user_id = null)
        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('I need a painting quote');
        expect($message->user_id)->toBeNull();

        // Session should exist with null user_id
        $session = SmsSession::where('phone_number', '+15550001111')
            ->where('thread_id', $thread->id)
            ->first();
        expect($session)->not->toBeNull();
        expect($session->user_id)->toBeNull();
    });

    it('routes follow-up reply to same support thread via session', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        // First cold inbound
        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Need a quote',
            'From' => '+15550002222',
        ]);

        $thread = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)->latest()->first();
        $initialMessageCount = $thread->messages()->count();

        // Second message (session is now active)
        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'For a 3 bedroom house',
            'From' => '+15550002222',
        ]);

        // Should route to same thread, not create a new one
        $thread->refresh();
        expect($thread->messages()->count())->toBe($initialMessageCount + 1);

        $latestMessage = $thread->messages()->latest()->first();
        expect($latestMessage->content)->toBe('For a 3 bedroom house');
    });
});

describe('metadata tracking', function () {
    it('includes sms source in message metadata for known users', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5553334444']);

        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Test message',
            'From' => '+15553334444',
        ]);

        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        $message = $thread->messages()->latest()->first();

        expect($message->metadata['source'])->toBe('sms');
    });
});

describe('NewThreadNotification broadcast', function () {
    it('fires NewThreadNotification when unknown number creates a support thread', function () {
        Event::fake([NewThreadNotification::class]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hello from a stranger',
            'From' => '+15557770001',
        ]);

        Event::assertDispatched(NewThreadNotification::class, function ($event) {
            return $event->thread->type === ChatThread::TYPE_SUPPORT
                && $event->thread->source === ChatThread::SOURCE_SMS_INBOUND;
        });
    });

    it('fires NewThreadNotification when known non-customer creates a support thread', function () {
        Event::fake([NewThreadNotification::class]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $crewUser = User::factory()->crew()->create();
        $crewUser->crewProfile()->update(['phone' => '5557770002']);

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'On my way to the job',
            'From' => '+15557770002',
        ]);

        Event::assertDispatched(NewThreadNotification::class, function ($event) use ($crewUser) {
            return $event->thread->type === ChatThread::TYPE_SUPPORT
                && $event->thread->hasParticipant($crewUser);
        });
    });
});

describe('participant fallback', function () {
    it('adds all active internal users when no users have chat.view_website_leads permission', function () {
        // Create internal users WITHOUT the specific permission
        $internal1 = User::factory()->internal()->create();
        $internal2 = User::factory()->internal()->create();

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Need help',
            'From' => '+15557770003',
        ]);

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->latest()
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($internal1))->toBeTrue();
        expect($thread->hasParticipant($internal2))->toBeTrue();
    });
});
