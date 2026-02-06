<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('renders the walkie-talkie room component', function () {
    actingAs($this->user)
        ->get('/walkie-talkie')
        ->assertStatus(200)
        ->assertSeeLivewire('walkie-talkie.room');
});

it('generates a room id when none is provided', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room')
        ->assertSet('userName', $this->user->name)
        ->assertNotSet('roomId', '');
});

it('uses the provided room id', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room', ['room' => 'test-room-123'])
        ->assertSet('roomId', 'test-room-123');
});

it('returns correct websocket url', function () {
    $component = Livewire::actingAs($this->user)
        ->test('walkie-talkie.room');

    $wsUrl = $component->instance()->getWebSocketUrl();

    expect($wsUrl)->toContain('/media/ws/audio');
});

it('toggles mute state', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room')
        ->assertSet('isMuted', false)
        ->call('toggleMute')
        ->assertSet('isMuted', true)
        ->call('toggleMute')
        ->assertSet('isMuted', false);
});

it('toggles transcription state', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room')
        ->assertSet('transcriptionEnabled', true)
        ->call('toggleTranscription')
        ->assertSet('transcriptionEnabled', false)
        ->call('toggleTranscription')
        ->assertSet('transcriptionEnabled', true);
});

it('can add messages', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room')
        ->assertSet('messages', [])
        ->call('addMessage', 'user-1', 'Test User', 'Hello world')
        ->assertCount('messages', 1);
});

it('limits messages to 50', function () {
    $component = Livewire::actingAs($this->user)
        ->test('walkie-talkie.room');

    // Add 55 messages
    for ($i = 0; $i < 55; $i++) {
        $component->call('addMessage', "user-{$i}", "User {$i}", "Message {$i}");
    }

    $component->assertCount('messages', 50);
});

it('can clear messages', function () {
    Livewire::actingAs($this->user)
        ->test('walkie-talkie.room')
        ->call('addMessage', 'user-1', 'Test User', 'Hello world')
        ->assertCount('messages', 1)
        ->call('clearMessages')
        ->assertSet('messages', []);
});

it('requires authentication', function () {
    $this->get('/walkie-talkie')
        ->assertRedirect('/login');
});

it('redirects non-internal users to their dashboard', function () {
    $customer = User::factory()->customer()->create();

    // Now redirects to customer dashboard instead of showing 403
    actingAs($customer)
        ->get('/walkie-talkie')
        ->assertRedirect(route('customer.dashboard'))
        ->assertSessionHas('warning');
});
