<?php

use App\Livewire\WalkieTalkie\Room;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('renders the walkie-talkie room component', function () {
    actingAs($this->user)
        ->get('/walkie-talkie')
        ->assertStatus(200)
        ->assertSeeLivewire(Room::class);
});

it('generates a room id when none is provided', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('userName', $this->user->name)
        ->assertNotSet('roomId', '');
});

it('uses the provided room id', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class, ['room' => 'test-room-123'])
        ->assertSet('roomId', 'test-room-123');
});

it('returns correct websocket url', function () {
    $component = Livewire::actingAs($this->user)
        ->test(Room::class);

    $wsUrl = $component->instance()->getWebSocketUrl();

    expect($wsUrl)->toContain('/media/ws/audio');
});

it('toggles mute state', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('isMuted', false)
        ->call('toggleMute')
        ->assertSet('isMuted', true)
        ->call('toggleMute')
        ->assertSet('isMuted', false);
});

it('toggles transcription state', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('transcriptionEnabled', true)
        ->call('toggleTranscription')
        ->assertSet('transcriptionEnabled', false)
        ->call('toggleTranscription')
        ->assertSet('transcriptionEnabled', true);
});

it('can add messages', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('messages', [])
        ->call('addMessage', 'user-1', 'Test User', 'Hello world')
        ->assertCount('messages', 1);
});

it('limits messages to 50', function () {
    $component = Livewire::actingAs($this->user)
        ->test(Room::class);

    // Add 55 messages
    for ($i = 0; $i < 55; $i++) {
        $component->call('addMessage', "user-{$i}", "User {$i}", "Message {$i}");
    }

    $component->assertCount('messages', 50);
});

it('can clear messages', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->call('addMessage', 'user-1', 'Test User', 'Hello world')
        ->assertCount('messages', 1)
        ->call('clearMessages')
        ->assertSet('messages', []);
});

it('requires authentication', function () {
    $this->get('/walkie-talkie')
        ->assertRedirect('/login');
});

it('requires internal user', function () {
    $customer = User::factory()->customer()->create();

    actingAs($customer)
        ->get('/walkie-talkie')
        ->assertForbidden();
});
