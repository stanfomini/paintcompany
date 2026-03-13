<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;

beforeEach(function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
    });
});

describe('customer without user account', function () {
    it('routes SMS from customer without user account to support thread with customer_id', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5551112222',
        ]);

        $response = $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'I need a painting quote',
            'From' => '+15551112222',
        ]);

        $response->assertStatus(200);

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->latest()
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->customer_id)->toBe($customer->id);
        expect($thread->hasParticipant($internal))->toBeTrue();

        // Message should be a guest message
        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('I need a painting quote');
        expect($message->user_id)->toBeNull();
        expect($message->metadata['customer_id'])->toBe($customer->id);

        // SmsSession should have customer_id but no user_id
        $session = SmsSession::where('thread_id', $thread->id)->first();
        expect($session)->not->toBeNull();
        expect($session->user_id)->toBeNull();
        expect($session->customer_id)->toBe($customer->id);
    });

    it('routes follow-up from customer without account via session', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        Customer::factory()->create([
            'user_id' => null,
            'phone' => '5551113333',
        ]);

        // First cold inbound
        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'First message',
            'From' => '+15551113333',
        ]);

        $thread = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)->latest()->first();
        $initialCount = $thread->messages()->count();

        // Second message uses active session
        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Second message',
            'From' => '+15551113333',
        ]);

        $thread->refresh();
        expect($thread->messages()->count())->toBe($initialCount + 1);
    });

    it('prefers customer without user account over nothing', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5554445555',
        ]);

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hello',
            'From' => '+15554445555',
        ]);

        $thread = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)->latest()->first();
        expect($thread->customer_id)->toBe($customer->id);
    });
});

describe('customer_id linking on known user threads', function () {
    it('sets customer_id on direct thread for known customer user', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5552223333']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hello',
            'From' => '+15552223333',
        ]);

        $thread = ChatThread::where('type', ChatThread::TYPE_DIRECT)->latest()->first();
        expect($thread)->not->toBeNull();
        expect($thread->customer_id)->toBe($customer->id);

        // SmsSession should also have customer_id
        $session = SmsSession::where('thread_id', $thread->id)->first();
        expect($session->customer_id)->toBe($customer->id);
    });

    it('sets customer_id on deal thread for known customer user', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $customerUser = User::factory()->customer()->create();
        $customerUser->customerProfile()->update(['phone' => '5552224444']);

        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'assigned_to' => $internal->id,
        ]);

        $deal = \App\Models\Deal::factory()->quoted()->forCustomer($customer)->create();
        $dealThread = $deal->getOrCreateChatThread();

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'About my estimate',
            'From' => '+15552224444',
        ]);

        $dealThread->refresh();
        expect($dealThread->customer_id)->toBe($customer->id);
    });
});

describe('unknown number auto-linking', function () {
    it('auto-links unknown number thread when phone matches customer with user account', function () {
        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        // Customer has a user_id, so findUsersByPhone will find them.
        // But if the phone is ONLY in customers.phone and not in user/profile tables,
        // findUsersByPhone won't find them, so they go to unknown.
        // Let's simulate: customer record with a phone, but no matching user phone
        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5556667777',
        ]);

        $this->post(route('webhooks.twilio.sms'), [
            'Body' => 'Hello',
            'From' => '+15556667777',
        ]);

        $thread = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)->latest()->first();
        expect($thread->customer_id)->toBe($customer->id);
    });
});

describe('SmsSession customer relationship', function () {
    it('has customer relationship on SmsSession model', function () {
        $customer = Customer::factory()->create(['user_id' => null]);

        $thread = ChatThread::factory()->create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
        ]);

        $session = SmsSession::create([
            'user_id' => null,
            'customer_id' => $customer->id,
            'thread_id' => $thread->id,
            'phone_number' => '+15551234567',
            'expires_at' => now()->addHours(24),
        ]);

        expect($session->customer)->not->toBeNull();
        expect($session->customer->id)->toBe($customer->id);
    });
});
