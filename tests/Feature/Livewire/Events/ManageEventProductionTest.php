<?php

use App\Models\ChatMessage;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\LiveEventSceneTemplate;
use App\Models\User;
use App\Services\LiveKitService;
use Livewire\Livewire;

beforeEach(function () {
    $this->internal = User::factory()->internal()->withFullAccess()->create();
    $this->event = LiveEvent::factory()->scheduled()->create([
        'host_user_id' => $this->internal->id,
    ]);

    $mockLiveKit = Mockery::mock(LiveKitService::class);
    $mockLiveKit->shouldReceive('createRoom')->byDefault();
    $mockLiveKit->shouldReceive('deleteRoom')->byDefault();
    $mockLiveKit->shouldReceive('generateHostToken')->byDefault()->andReturn('mock-jwt-token');
    $mockLiveKit->shouldReceive('generateGuestToken')->byDefault()->andReturn('mock-guest-token');
    $mockLiveKit->shouldReceive('generateCoproducerToken')->byDefault()->andReturn('mock-coprod-token');
    $mockLiveKit->shouldReceive('getParticipantCount')->byDefault()->andReturn(0);
    app()->instance(LiveKitService::class, $mockLiveKit);
});

// ============================================================
// Guest Management (Pre-Live)
// ============================================================

describe('guest management', function () {
    it('invites a guest', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('guestName', 'Jane Smith')
            ->set('guestEmail', 'jane@example.com')
            ->set('guestPhone', '555-1234')
            ->set('guestInstructions', 'Talk about kitchen remodels')
            ->call('inviteGuest');

        $guest = $this->event->guests()->first();

        expect($guest)->not->toBeNull()
            ->and($guest->name)->toBe('Jane Smith')
            ->and($guest->email)->toBe('jane@example.com')
            ->and($guest->instructions)->toBe('Talk about kitchen remodels')
            ->and($guest->status)->toBe('invited');
    });

    it('validates guest name is required', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('guestName', '')
            ->call('inviteGuest')
            ->assertHasErrors(['guestName']);
    });

    it('validates guest email format', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('guestName', 'Test')
            ->set('guestEmail', 'not-an-email')
            ->call('inviteGuest')
            ->assertHasErrors(['guestEmail']);
    });

    it('removes a guest', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('removeGuest', $guest->id);

        expect(LiveEventGuest::find($guest->id))->toBeNull();
    });

    it('prevents removing guests from other events', function () {
        $otherEvent = LiveEvent::factory()->create();
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $otherEvent->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('removeGuest', $guest->id);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    it('copies guest link and dispatches clipboard event', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('copyGuestLink', $guest->id)
            ->assertDispatched('copy-to-clipboard');
    });

    it('resets form after inviting guest', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('guestName', 'Jane Smith')
            ->set('guestEmail', 'jane@example.com')
            ->call('inviteGuest')
            ->assertSet('guestName', '')
            ->assertSet('guestEmail', '');
    });
});

// ============================================================
// Scene & Overlay Management
// ============================================================

describe('scene management', function () {
    it('updates scenes on event', function () {
        $scenes = [
            ['name' => 'Custom', 'slots' => [['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]], 'overlays' => []],
        ];

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('updateScenes', $scenes);

        expect($this->event->fresh()->scenes)->toHaveCount(1)
            ->and($this->event->fresh()->scenes[0]['name'])->toBe('Custom');
    });

    it('switches active scene', function () {
        $scenes = LiveEventSceneTemplate::defaultScenes();
        $this->event->update(['scenes' => $scenes]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('switchScene', 2)
            ->assertDispatched('scene-switched');

        expect($this->event->fresh()->active_scene_index)->toBe(2);
    });

    it('does not switch to invalid scene index', function () {
        $scenes = LiveEventSceneTemplate::defaultScenes();
        $this->event->update(['scenes' => $scenes]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('switchScene', 99);

        expect($this->event->fresh()->active_scene_index)->toBe(0);
    });

    it('toggles frame mode', function () {
        $this->event->update(['use_frame_mode' => true]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('toggleFrameMode');

        expect($this->event->fresh()->use_frame_mode)->toBeFalse();
    });
});

describe('overlay management', function () {
    it('stores overlays within scene data', function () {
        $scenes = LiveEventSceneTemplate::defaultLayouts();
        $scenes[0]['overlays'] = [
            ['type' => 'text', 'content' => 'Hello World', 'visible' => true, 'x' => 0.1, 'y' => 0.85],
        ];

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('updateScenes', $scenes);

        $updatedScenes = $this->event->fresh()->scenes;
        expect($updatedScenes[0]['overlays'])->toHaveCount(1)
            ->and($updatedScenes[0]['overlays'][0]['content'])->toBe('Hello World');
    });

    it('removes overlays via updateScenes', function () {
        $scenes = LiveEventSceneTemplate::defaultLayouts();
        $scenes[0]['overlays'] = [
            ['type' => 'text', 'content' => 'Keep me', 'visible' => true],
            ['type' => 'text', 'content' => 'Remove me', 'visible' => true],
        ];
        $this->event->update(['scenes' => $scenes]);

        $scenes[0]['overlays'] = [
            ['type' => 'text', 'content' => 'Keep me', 'visible' => true],
        ];

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('updateScenes', $scenes);

        expect($this->event->fresh()->scenes[0]['overlays'])->toHaveCount(1)
            ->and($this->event->fresh()->scenes[0]['overlays'][0]['content'])->toBe('Keep me');
    });
});

// ============================================================
// Scene Templates
// ============================================================

describe('scene templates', function () {
    it('saves a scene as template', function () {
        $scene = LiveEventSceneTemplate::defaultLayouts()[0];
        $scene['overlays'] = [
            ['type' => 'text', 'content' => 'My overlay', 'visible' => true],
        ];

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('templateName', 'My Solo Scene')
            ->call('saveAsTemplate', $scene)
            ->assertDispatched('template-saved');

        $template = LiveEventSceneTemplate::where('user_id', $this->internal->id)->first();
        expect($template->name)->toBe('My Solo Scene')
            ->and($template->scene_data)->toBeArray()
            ->and($template->scene_data['overlays'])->toHaveCount(1);
    });

    it('validates template name is required', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('templateName', '')
            ->call('saveAsTemplate')
            ->assertHasErrors(['templateName']);
    });

    it('loads a saved template into event scenes', function () {
        $scene = LiveEventSceneTemplate::defaultLayouts()[1];
        $scene['overlays'] = [
            ['type' => 'text', 'content' => 'Interview Title', 'visible' => true],
        ];

        $template = LiveEventSceneTemplate::create([
            'user_id' => $this->internal->id,
            'name' => 'My Interview',
            'scene_data' => $scene,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('loadTemplate', $template->id)
            ->assertDispatched('template-loaded');

        $eventScenes = $this->event->fresh()->scenes;
        $lastScene = end($eventScenes);
        expect($lastScene['name'])->toBe('Interview')
            ->and($lastScene['overlays'])->toHaveCount(1)
            ->and($lastScene['overlays'][0]['content'])->toBe('Interview Title');
    });

    it('prevents loading another users template', function () {
        $otherUser = User::factory()->internal()->create();
        $template = LiveEventSceneTemplate::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('loadTemplate', $template->id);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

// ============================================================
// Live Production Controls
// ============================================================

describe('live guest controls', function () {
    it('brings a guest on air', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        $guest = LiveEventGuest::factory()->connected()->create([
            'live_event_id' => $live->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->call('bringGuestOn', $guest->id)
            ->assertDispatched('guest-bring-on');

        expect($guest->fresh()->status)->toBe('live')
            ->and($guest->fresh()->went_live_at)->not->toBeNull();
    });

    it('takes a guest off air', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        $guest = LiveEventGuest::factory()->live()->create([
            'live_event_id' => $live->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->call('takeGuestOff', $guest->id)
            ->assertDispatched('guest-take-off');

        expect($guest->fresh()->status)->toBe('connected')
            ->and($guest->fresh()->went_live_at)->toBeNull();
    });

    it('prevents controlling guests from other events', function () {
        $otherEvent = LiveEvent::factory()->live()->create();
        $guest = LiveEventGuest::factory()->connected()->create([
            'live_event_id' => $otherEvent->id,
        ]);

        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->call('bringGuestOn', $guest->id);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

// ============================================================
// Backstage Chat
// ============================================================

describe('backstage chat', function () {
    it('sends a backstage message', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('backstageMessage', 'Hey, ready to go?')
            ->call('sendBackstageMessage');

        $this->event->refresh();

        expect($this->event->backstage_chat_thread_id)->not->toBeNull();

        $message = ChatMessage::where('thread_id', $this->event->backstage_chat_thread_id)->first();

        expect($message)->not->toBeNull()
            ->and($message->content)->toContain('Hey, ready to go?');
    });

    it('validates backstage message is required', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('backstageMessage', '')
            ->call('sendBackstageMessage')
            ->assertHasErrors(['backstageMessage']);
    });

    it('resets backstage message after sending', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('backstageMessage', 'Test message')
            ->call('sendBackstageMessage')
            ->assertSet('backstageMessage', '');
    });

    it('creates backstage thread only once', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('backstageMessage', 'First')
            ->call('sendBackstageMessage')
            ->set('backstageMessage', 'Second')
            ->call('sendBackstageMessage');

        $this->event->refresh();

        $count = ChatMessage::where('thread_id', $this->event->backstage_chat_thread_id)->count();

        expect($count)->toBe(2);
    });
});

// ============================================================
// Guest Green Room Page
// ============================================================

describe('guest green room', function () {
    it('loads with valid token', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
        ]);

        $this->get(route('events.guest-room', ['token' => $guest->token]))
            ->assertOk();
    });

    it('returns 404 for invalid token', function () {
        $this->get(route('events.guest-room', ['token' => 'invalid-token-xyz']))
            ->assertNotFound();
    });

    it('shows event ended page for ended events', function () {
        $ended = LiveEvent::factory()->ended()->create();
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $ended->id,
        ]);

        $this->get(route('events.guest-room', ['token' => $guest->token]))
            ->assertOk()
            ->assertSee('Event Has Ended');
    });

    it('marks guest as connected on page load', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
            'status' => 'invited',
        ]);

        $this->get(route('events.guest-room', ['token' => $guest->token]));

        expect($guest->fresh()->status)->toBe('connected')
            ->and($guest->fresh()->connected_at)->not->toBeNull();
    });

    it('shows guest instructions', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
            'instructions' => 'Talk about bathroom renovations',
        ]);

        $this->get(route('events.guest-room', ['token' => $guest->token]))
            ->assertOk()
            ->assertSee('Talk about bathroom renovations');
    });

    it('shows event title', function () {
        $guest = LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
        ]);

        $this->get(route('events.guest-room', ['token' => $guest->token]))
            ->assertOk()
            ->assertSee($this->event->title);
    });
});

// ============================================================
// Pre-Live UI Elements
// ============================================================

describe('pre-live production setup', function () {
    it('shows production setup section', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertSee('Production Setup')
            ->assertSee('Scene Presets')
            ->assertSee('Guest Management');
    });

    it('shows invited guests in pre-live state', function () {
        LiveEventGuest::factory()->create([
            'live_event_id' => $this->event->id,
            'name' => 'Remote Guest Jane',
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertSee('Remote Guest Jane');
    });

    it('shows scene preset options', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertSee('Solo')
            ->assertSee('Interview')
            ->assertSee('Guest Spotlight');
    });
});
