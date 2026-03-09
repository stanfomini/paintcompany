<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\MessengerConversation;
use App\Models\User;
use App\Services\FacebookMessengerService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Customer Communications Hub - Send Booking Link', function () {
    it('sends booking link for a messenger thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $thread = ChatThread::factory()->facebook()->forCustomer($customer)->create();
        $conversation = MessengerConversation::factory()->create([
            'thread_id' => $thread->id,
            'facebook_psid' => '1234567890',
        ]);

        $mock = mock(FacebookMessengerService::class);
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->withArgs(function ($psid, $message) {
                return $psid === '1234567890' && str_contains($message, '/book/messenger/');
            });
        app()->instance(FacebookMessengerService::class, $mock);

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('sendBookingLink', $thread->id)
            ->assertHasNoErrors();

        // Verify booking token was generated
        $conversation->refresh();
        expect($conversation->booking_token)->not->toBeNull();
        expect($conversation->booking_token_expires_at)->not->toBeNull();

        // Verify system message was created in thread
        $systemMessage = ChatMessage::where('thread_id', $thread->id)
            ->where('type', ChatMessage::TYPE_SYSTEM)
            ->first();
        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('Booking link sent');
    });

    it('does nothing for invalid thread id', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('sendBookingLink', 99999)
            ->assertHasNoErrors();
    });

    it('does nothing for thread without messenger conversation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $thread = ChatThread::factory()->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('sendBookingLink', $thread->id)
            ->assertHasNoErrors();
    });
});
