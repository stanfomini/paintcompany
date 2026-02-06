<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('renders the video call room component', function () {
    actingAs($this->user)
        ->get('/video-call')
        ->assertStatus(200)
        ->assertSeeLivewire('video-call.room');
});

it('generates a room id when none is provided', function () {
    Livewire::actingAs($this->user)
        ->test('video-call.room')
        ->assertSet('userName', $this->user->name)
        ->assertNotSet('roomId', '');
});

it('uses the provided room id', function () {
    Livewire::actingAs($this->user)
        ->test('video-call.room', ['room' => 'test-video-room'])
        ->assertSet('roomId', 'test-video-room');
});

it('returns correct websocket url', function () {
    $component = Livewire::actingAs($this->user)
        ->test('video-call.room');

    $wsUrl = $component->instance()->getWebSocketUrl();

    expect($wsUrl)->toContain('/media/ws/video');
});

it('toggles audio state', function () {
    Livewire::actingAs($this->user)
        ->test('video-call.room')
        ->assertSet('isAudioEnabled', true)
        ->call('toggleAudio')
        ->assertSet('isAudioEnabled', false)
        ->call('toggleAudio')
        ->assertSet('isAudioEnabled', true);
});

it('toggles video state', function () {
    Livewire::actingAs($this->user)
        ->test('video-call.room')
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

it('redirects non-internal users to their dashboard', function () {
    $customer = User::factory()->customer()->create();

    // Now redirects to customer dashboard instead of showing 403
    actingAs($customer)
        ->get('/video-call')
        ->assertRedirect(route('customer.dashboard'))
        ->assertSessionHas('warning');
});
