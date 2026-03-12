<?php

use App\Events\NewThreadNotification;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
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

describe('CRM phone lookup', function () {
    it('finds customer by customers.phone when profile phone is empty', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        // Profile phone is null — only CRM phone is set
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
            'phone' => '5558881111',
        ]);

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Found via CRM phone',
            'From' => '+15558881111',
        ]);

        $response->assertStatus(200);

        // Should route as known customer, not unknown
        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($customerUser))->toBeTrue();

        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('Found via CRM phone');
        expect($message->user_id)->toBe($customerUser->id);
    });
});

describe('phone sync', function () {
    it('syncs customers.phone to customer_profiles.phone on save', function () {
        $customerUser = User::factory()->customer()->create();
        expect($customerUser->customerProfile->phone)->toBeNull();

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'phone' => '5559991234',
        ]);

        $customerUser->refresh();
        expect($customerUser->customerProfile->phone)->toBe('5559991234');
    });

    it('updates customer_profiles.phone when customers.phone changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'phone' => '5550001111',
        ]);

        $customerUser->refresh();
        expect($customerUser->customerProfile->phone)->toBe('5550001111');

        $customer->update(['phone' => '5550002222']);

        $customerUser->refresh();
        expect($customerUser->customerProfile->phone)->toBe('5550002222');
    });
});

describe('deal thread routing', function () {
    it('routes cold inbound to active deal chat thread when one exists', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5556661111']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        // Create an active deal with a chat thread
        $deal = Deal::factory()->quoted()->forCustomer($customer)->create();
        $dealThread = $deal->getOrCreateChatThread();

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Question about my estimate',
            'From' => '+15556661111',
        ]);

        $response->assertStatus(200);

        // Message should go to the deal thread, not a new direct thread
        $message = $dealThread->messages()->latest()->first();
        expect($message)->not->toBeNull();
        expect($message->content)->toBe('Question about my estimate');
        expect($message->user_id)->toBe($customerUser->id);

        // Should NOT have created a new direct thread
        $directThread = ChatThread::where('type', ChatThread::TYPE_DIRECT)
            ->latest()
            ->first();
        expect($directThread)->toBeNull();
    });

    it('falls back to direct thread when deal has no chat thread', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5556662222']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        // Active deal but NO chat thread created
        Deal::factory()->quoted()->forCustomer($customer)->create();

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hello',
            'From' => '+15556662222',
        ]);

        $response->assertStatus(200);

        // Should create a direct thread instead
        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($customerUser))->toBeTrue();
        expect($thread->hasParticipant($internal))->toBeTrue();
    });

    it('ignores terminal deal threads and creates direct thread', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5556663333']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        // Create a completed (terminal) deal with a chat thread
        $deal = Deal::factory()->completed()->forCustomer($customer)->create();
        $deal->getOrCreateChatThread();

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'New inquiry',
            'From' => '+15556663333',
        ]);

        $response->assertStatus(200);

        // Should create a direct thread since the deal is terminal
        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($customerUser))->toBeTrue();
    });
});

describe('outbound SMS phone fallback', function () {
    it('resolves phone from customers table when profile phone is null', function () {
        $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
            $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => '+1'.$phone);
        });

        $customerUser = User::factory()->customer()->create();
        // Profile phone is null
        expect($customerUser->customerProfile->phone)->toBeNull();

        // Set CRM phone only
        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'phone' => '5557774444',
        ]);

        $customerUser->refresh();
        $resolved = $customerUser->routeNotificationForTwilio();
        expect($resolved)->toBe('+15557774444');
    });
});
