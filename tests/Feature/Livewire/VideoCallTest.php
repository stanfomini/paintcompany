<?php

use App\Livewire\VideoCall\Room;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('renders the video call room component', function () {
    actingAs($this->user)
        ->get('/video-call')
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
        ->test(Room::class, ['room' => 'test-video-room'])
        ->assertSet('roomId', 'test-video-room');
});

it('returns correct websocket url', function () {
    $component = Livewire::actingAs($this->user)
        ->test(Room::class);

    $wsUrl = $component->instance()->getWebSocketUrl();

    expect($wsUrl)->toContain('/media/ws/video');
});

it('toggles audio state', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('isAudioEnabled', true)
        ->call('toggleAudio')
        ->assertSet('isAudioEnabled', false)
        ->call('toggleAudio')
        ->assertSet('isAudioEnabled', true);
});

it('toggles video state', function () {
    Livewire::actingAs($this->user)
        ->test(Room::class)
        ->assertSet('isVideoEnabled', true)
        ->call('toggleVideo')
        ->assertSet('isVideoEnabled', false)
        ->call('toggleVideo')
        ->assertSet('isVideoEnabled', true);
});

it('requires authentication', function () {
    $this->get('/video-call')
        ->assertRedirect('/login');
});

it('requires internal user', function () {
    $customer = User::factory()->customer()->create();

    actingAs($customer)
        ->get('/video-call')
        ->assertForbidden();
});
