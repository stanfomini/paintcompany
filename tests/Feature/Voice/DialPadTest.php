<?php

use App\Models\CallLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('phone.make_calls');
    $this->actingAs($this->user);
});

it('renders for users with phone.make_calls permission', function () {
    Livewire::test('communications.dial-pad')
        ->assertStatus(200);
});

it('starts with isOpen false', function () {
    Livewire::test('communications.dial-pad')
        ->assertSet('isOpen', false)
        ->assertSet('phoneNumber', '')
        ->assertSet('callState', 'idle');
});

it('opens via open method', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->assertSet('isOpen', true)
        ->assertSet('callState', 'idle');
});

it('opens with pre-filled phone number', function () {
    Livewire::test('communications.dial-pad')
        ->call('open', '+13035551234')
        ->assertSet('isOpen', true)
        ->assertSet('phoneNumber', '+13035551234');
});

it('opens via open-dial-pad event', function () {
    Livewire::test('communications.dial-pad')
        ->dispatch('open-dial-pad')
        ->assertSet('isOpen', true);
});

it('closes when idle', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->assertSet('isOpen', true)
        ->call('close')
        ->assertSet('isOpen', false);
});

it('prevents closing while calling', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('callState', 'calling')
        ->call('close')
        ->assertSet('isOpen', true);
});

it('prevents closing while connected', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('callState', 'connected')
        ->call('close')
        ->assertSet('isOpen', true);
});

it('appends digit to phone number', function () {
    Livewire::test('communications.dial-pad')
        ->call('appendDigit', '5')
        ->assertSet('phoneNumber', '5');
});

it('builds full number from multiple digits', function () {
    Livewire::test('communications.dial-pad')
        ->call('appendDigit', '3')
        ->call('appendDigit', '0')
        ->call('appendDigit', '3')
        ->call('appendDigit', '5')
        ->call('appendDigit', '5')
        ->call('appendDigit', '5')
        ->call('appendDigit', '1')
        ->call('appendDigit', '2')
        ->call('appendDigit', '3')
        ->call('appendDigit', '4')
        ->assertSet('phoneNumber', '3035551234');
});

it('limits phone number to 20 characters', function () {
    $component = Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '12345678901234567890');

    $component->call('appendDigit', '1')
        ->assertSet('phoneNumber', '12345678901234567890');
});

it('removes last digit with backspace', function () {
    Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '3035551234')
        ->call('backspace')
        ->assertSet('phoneNumber', '303555123');
});

it('handles backspace on empty string without error', function () {
    Livewire::test('communications.dial-pad')
        ->call('backspace')
        ->assertSet('phoneNumber', '');
});

it('clears the phone number', function () {
    Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '3035551234')
        ->call('clearNumber')
        ->assertSet('phoneNumber', '');
});

it('sets phone number from contact selection', function () {
    Livewire::test('communications.dial-pad')
        ->call('selectContact', '+13035551234')
        ->assertSet('phoneNumber', '+13035551234');
});

it('does not dial with empty phone number', function () {
    Livewire::test('communications.dial-pad')
        ->call('dial')
        ->assertSet('callState', 'idle');
});

it('dispatches error when user has no forwarding number', function () {
    Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '+13035551234')
        ->call('dial')
        ->assertDispatched('notify')
        ->assertSet('callState', 'idle');
});

it('closes dialpad and dispatches calling toast on successful dial', function () {
    $this->user->update(['forwarding_number' => '+13035550000']);

    $callLog = CallLog::factory()->outbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035551234',
    ]);

    $mock = mock(\App\Services\TwilioVoiceService::class)
        ->shouldReceive('initiateOutboundCall')
        ->once()
        ->andReturn($callLog)
        ->getMock();

    app()->instance(\App\Services\TwilioVoiceService::class, $mock);

    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('phoneNumber', '+13035551234')
        ->call('dial')
        ->assertSet('callState', 'calling')
        ->assertSet('isOpen', false)
        ->assertDispatched('notify');
});

it('resets dial pad state', function () {
    Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '3035551234')
        ->set('callState', 'ended')
        ->set('activeCallLogId', 99)
        ->set('activeCallLogUlid', 'abc123')
        ->call('resetDialPad')
        ->assertSet('phoneNumber', '')
        ->assertSet('callState', 'idle')
        ->assertSet('activeCallLogId', null)
        ->assertSet('activeCallLogUlid', null);
});

it('returns recent outbound contacts for current user', function () {
    CallLog::factory()->outbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035551111',
    ]);
    CallLog::factory()->outbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035552222',
    ]);

    // Inbound call should not appear
    CallLog::factory()->inbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035553333',
    ]);

    $component = Livewire::test('communications.dial-pad');
    $contacts = $component->invade()->recentContacts;

    expect($contacts)->toHaveCount(2)
        ->and($contacts->pluck('to_number')->toArray())
        ->toContain('+13035551111', '+13035552222')
        ->not->toContain('+13035553333');
});

it('returns unique to_numbers in recent contacts', function () {
    CallLog::factory()->outbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035551111',
        'created_at' => now()->subMinutes(2),
    ]);
    CallLog::factory()->outbound()->create([
        'caller_user_id' => $this->user->id,
        'to_number' => '+13035551111',
        'created_at' => now()->subMinute(),
    ]);

    $component = Livewire::test('communications.dial-pad');
    $contacts = $component->invade()->recentContacts;

    expect($contacts)->toHaveCount(1);
});

it('limits recent contacts to 5', function () {
    for ($i = 1; $i <= 7; $i++) {
        CallLog::factory()->outbound()->create([
            'caller_user_id' => $this->user->id,
            'to_number' => "+1303555000{$i}",
        ]);
    }

    $component = Livewire::test('communications.dial-pad');
    $contacts = $component->invade()->recentContacts;

    expect($contacts)->toHaveCount(5);
});

it('does not show other users recent contacts', function () {
    $otherUser = User::factory()->internal()->create();

    CallLog::factory()->outbound()->create([
        'caller_user_id' => $otherUser->id,
        'to_number' => '+13035559999',
    ]);

    $component = Livewire::test('communications.dial-pad');
    $contacts = $component->invade()->recentContacts;

    expect($contacts)->toHaveCount(0);
});

it('updates call state on connected event and closes dialpad', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('activeCallLogId', 42)
        ->call('onCallConnected', ['call_log_id' => 42])
        ->assertSet('callState', 'connected')
        ->assertSet('isOpen', false);
});

it('ignores connected event for different call', function () {
    Livewire::test('communications.dial-pad')
        ->set('activeCallLogId', 42)
        ->call('onCallConnected', ['call_log_id' => 99])
        ->assertSet('callState', 'idle');
});

it('updates call state on ended event', function () {
    Livewire::test('communications.dial-pad')
        ->set('activeCallLogId', 42)
        ->set('callState', 'connected')
        ->call('onCallEnded', ['call_log_id' => 42])
        ->assertSet('callState', 'ended');
});

it('ignores ended event for different call', function () {
    Livewire::test('communications.dial-pad')
        ->set('activeCallLogId', 42)
        ->set('callState', 'connected')
        ->call('onCallEnded', ['call_log_id' => 99])
        ->assertSet('callState', 'connected');
});

it('shows Make a Call header when open', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->assertSee('Make a Call');
});

it('shows keypad digits when idle', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->assertSee('ABC')
        ->assertSee('DEF')
        ->assertSee('WXYZ');
});

it('shows calling state text', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('callState', 'calling')
        ->assertSee('Calling your phone...');
});

it('shows connected state text', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('callState', 'connected')
        ->assertSee('Connected');
});

it('shows new call button when ended', function () {
    Livewire::test('communications.dial-pad')
        ->call('open')
        ->set('callState', 'ended')
        ->assertSee('New Call');
});

it('open resets previous call state', function () {
    Livewire::test('communications.dial-pad')
        ->set('phoneNumber', '1234567890')
        ->set('callState', 'connected')
        ->set('activeCallLogId', 99)
        ->set('activeCallLogUlid', 'abc123')
        ->call('open')
        ->assertSet('phoneNumber', '')
        ->assertSet('callState', 'idle')
        ->assertSet('activeCallLogId', null)
        ->assertSet('activeCallLogUlid', null);
});
