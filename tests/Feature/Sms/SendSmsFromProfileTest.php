<?php

use App\Jobs\SendDirectSmsJob;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->internal = User::factory()->internal()->create();
    $this->internal->assignRole('admin');
    $this->actingAs($this->internal);
});

describe('SendDirectSmsJob', function () {
    it('sends SMS and creates chat thread', function () {
        $mock = $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('sendSms')
                ->once()
                ->with('+15551234567', 'Test message')
                ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
            $mock->shouldReceive('formatE164')
                ->andReturn('+15551234567');
        });

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5551234567',
        ]);

        $job = new SendDirectSmsJob(
            phone: '+15551234567',
            message: 'Test message',
            sender: $this->internal,
            customer: $customer,
        );

        $job->handle();

        // Should create a thread with customer_id
        $thread = ChatThread::where('customer_id', $customer->id)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->customer_id)->toBe($customer->id);

        // Should have the outbound message
        $message = $thread->messages()->latest()->first();
        expect($message->content)->toBe('Test message');
        expect($message->user_id)->toBe($this->internal->id);
        expect($message->metadata['source'])->toBe('sms');
        expect($message->metadata['direction'])->toBe('outbound');

        // Should create SmsSession
        $session = SmsSession::where('thread_id', $thread->id)->first();
        expect($session)->not->toBeNull();
        expect($session->customer_id)->toBe($customer->id);
    });

    it('reuses existing SMS thread for same customer', function () {
        $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('sendSms')
                ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
            $mock->shouldReceive('formatE164')
                ->andReturn('+15559876543');
        });

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5559876543',
        ]);

        // Create existing SMS thread for this customer
        $existingThread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'customer_id' => $customer->id,
            'subject' => 'SMS from +15559876543',
            'last_message_at' => now(),
        ]);
        $existingThread->addParticipant($this->internal);

        $job = new SendDirectSmsJob(
            phone: '+15559876543',
            message: 'Follow up message',
            sender: $this->internal,
            customer: $customer,
        );

        $job->handle();

        // Should reuse the existing thread
        $threads = ChatThread::where('customer_id', $customer->id)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->get();

        expect($threads)->toHaveCount(1);
        expect($threads->first()->id)->toBe($existingThread->id);
    });

    it('handles SMS send failure gracefully', function () {
        $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('sendSms')
                ->once()
                ->andReturn(['success' => false, 'sid' => null, 'error' => 'Rate limit exceeded']);
            $mock->shouldReceive('formatE164')
                ->andReturn('+15551111111');
        });

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5551111111',
        ]);

        $job = new SendDirectSmsJob(
            phone: '+15551111111',
            message: 'Should fail',
            sender: $this->internal,
            customer: $customer,
        );

        $job->handle();

        // Should NOT create any thread or message on failure
        $thread = ChatThread::where('customer_id', $customer->id)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->first();

        expect($thread)->toBeNull();
    });
});

describe('send SMS modal component', function () {
    it('dispatches SendDirectSmsJob when sending SMS', function () {
        Queue::fake();

        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5553334444',
        ]);

        Livewire::test('customers.send-sms-modal', ['customer' => $customer])
            ->set('messageBody', 'Hello from the team!')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('send')
            ->assertSet('showModal', false)
            ->assertSet('messageBody', '');

        Queue::assertPushed(SendDirectSmsJob::class, function ($job) use ($customer) {
            return $job->message === 'Hello from the team!'
                && $job->customer->id === $customer->id;
        });
    });

    it('validates message is required', function () {
        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5553335555',
        ]);

        Livewire::test('customers.send-sms-modal', ['customer' => $customer])
            ->call('openModal')
            ->set('messageBody', '')
            ->call('send')
            ->assertHasErrors(['messageBody' => 'required']);
    });

    it('validates message max length', function () {
        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => '5553336666',
        ]);

        Livewire::test('customers.send-sms-modal', ['customer' => $customer])
            ->call('openModal')
            ->set('messageBody', str_repeat('a', 1601))
            ->call('send')
            ->assertHasErrors(['messageBody' => 'max']);
    });

    it('requires customer to have a phone number', function () {
        $customer = Customer::factory()->create([
            'user_id' => null,
            'phone' => null,
        ]);

        Queue::fake();

        Livewire::test('customers.send-sms-modal', ['customer' => $customer])
            ->call('openModal')
            ->assertSet('showModal', false) // Should not open without phone
            ->set('messageBody', 'Hello')
            ->call('send')
            ->assertSet('showModal', false);

        Queue::assertNotPushed(SendDirectSmsJob::class);
    });
});
