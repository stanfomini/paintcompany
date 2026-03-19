<?php

use App\Models\CallLog;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('phone.view_call_history');
    $this->user->givePermissionTo('phone.view_company_voicemails');
    $this->actingAs($this->user);
});

it('renders for users with phone.view_call_history permission', function () {
    Livewire::test('communications.call-history')
        ->assertStatus(200);
});

it('returns 403 without permission', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    Livewire::test('communications.call-history')
        ->assertForbidden();
});

it('shows empty state when no call logs exist', function () {
    Livewire::test('communications.call-history')
        ->assertSee('No call history found.');
});

it('displays call logs', function () {
    $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    CallLog::factory()->inbound()->create([
        'customer_id' => $customer->id,
        'from_number' => '+13035551234',
    ]);

    Livewire::test('communications.call-history')
        ->assertSee('Jane Doe')
        ->assertSee('+13035551234');
});

it('filters by missed calls', function () {
    CallLog::factory()->completed()->create(['from_number' => '+10001111111']);
    CallLog::factory()->missed()->create(['from_number' => '+10002222222']);

    Livewire::test('communications.call-history')
        ->call('setFilter', 'missed')
        ->assertSee('+10002222222')
        ->assertDontSee('+10001111111');
});

it('filters by voicemail', function () {
    CallLog::factory()->completed()->create(['from_number' => '+10001111111']);
    CallLog::factory()->voicemail()->create([
        'from_number' => '+10003333333',
        'voicemail_transcription' => 'Please call me back',
    ]);

    Livewire::test('communications.call-history')
        ->call('setFilter', 'voicemail')
        ->assertSee('+10003333333')
        ->assertSee('Please call me back')
        ->assertDontSee('+10001111111');
});

it('filters by inbound direction', function () {
    CallLog::factory()->inbound()->create(['from_number' => '+10001111111']);
    CallLog::factory()->outbound()->create(['to_number' => '+10002222222']);

    Livewire::test('communications.call-history')
        ->call('setFilter', 'inbound')
        ->assertSee('+10001111111')
        ->assertDontSee('+10002222222');
});

it('filters by outbound direction', function () {
    CallLog::factory()->inbound()->create(['from_number' => '+10001111111']);
    CallLog::factory()->outbound()->create(['to_number' => '+10002222222']);

    Livewire::test('communications.call-history')
        ->call('setFilter', 'outbound')
        ->assertSee('+10002222222')
        ->assertDontSee('+10001111111');
});

it('searches by phone number', function () {
    CallLog::factory()->create(['from_number' => '+13035551234']);
    CallLog::factory()->create(['from_number' => '+17205559999']);

    Livewire::test('communications.call-history')
        ->set('search', '5551234')
        ->assertSee('+13035551234')
        ->assertDontSee('+17205559999');
});

it('searches by customer name', function () {
    $customer = Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Smith']);
    CallLog::factory()->create(['customer_id' => $customer->id, 'from_number' => '+10001111111']);
    CallLog::factory()->create(['from_number' => '+10002222222']);

    Livewire::test('communications.call-history')
        ->set('search', 'Alice')
        ->assertSee('Alice Smith')
        ->assertDontSee('+10002222222');
});

it('dispatches open-dial-pad when calling back', function () {
    Livewire::test('communications.call-history')
        ->call('callBack', '+13035551234')
        ->assertDispatched('open-dial-pad', phone: '+13035551234');
});

it('shows status badges correctly', function () {
    CallLog::factory()->missed()->create();
    CallLog::factory()->voicemail()->create();
    CallLog::factory()->completed()->create();

    Livewire::test('communications.call-history')
        ->assertSee('Missed')
        ->assertSee('Voicemail')
        ->assertSee('Completed');
});

it('resets page when filter changes', function () {
    Livewire::test('communications.call-history')
        ->call('setFilter', 'missed')
        ->assertSet('filter', 'missed');
});
