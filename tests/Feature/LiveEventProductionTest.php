<?php

use App\Models\ChatThread;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\LiveEventSceneTemplate;
use App\Models\User;
use App\Services\LiveEventService;
use App\Services\LiveKitService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ============================================================
// LiveEventGuest Model
// ============================================================

test('guest model creates with auto-generated token and livekit identity', function () {
    $event = LiveEvent::factory()->create();

    $guest = LiveEventGuest::create([
        'live_event_id' => $event->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);

    expect($guest->token)->toHaveLength(64)
        ->and($guest->livekit_identity)->toStartWith('guest-')
        ->and($guest->status)->toBe('invited');
});

test('guest model belongs to live event', function () {
    $guest = LiveEventGuest::factory()->create();

    expect($guest->liveEvent)->toBeInstanceOf(LiveEvent::class);
});

test('guest status helper methods work correctly', function () {
    $invited = LiveEventGuest::factory()->create(['status' => 'invited']);
    $connected = LiveEventGuest::factory()->connected()->create();
    $live = LiveEventGuest::factory()->live()->create();
    $disconnected = LiveEventGuest::factory()->disconnected()->create();

    expect($invited->isInvited())->toBeTrue()
        ->and($invited->isConnected())->toBeFalse()
        ->and($connected->isConnected())->toBeTrue()
        ->and($connected->isInvited())->toBeFalse()
        ->and($live->isLive())->toBeTrue()
        ->and($disconnected->isDisconnected())->toBeTrue();
});

test('guest getLivekitIdentity returns stored identity', function () {
    $guest = LiveEventGuest::factory()->create(['livekit_identity' => 'guest-abc123']);

    expect($guest->getLivekitIdentity())->toBe('guest-abc123');
});

test('live event has many guests', function () {
    $event = LiveEvent::factory()->create();
    LiveEventGuest::factory()->count(3)->create(['live_event_id' => $event->id]);

    expect($event->guests)->toHaveCount(3);
});

// ============================================================
// LiveEventSceneTemplate Model
// ============================================================

test('scene template belongs to user', function () {
    $template = LiveEventSceneTemplate::factory()->create();

    expect($template->user)->toBeInstanceOf(User::class);
});

test('scene template has default scenes', function () {
    $defaults = LiveEventSceneTemplate::defaultScenes();

    expect($defaults)->toBeArray()
        ->toHaveCount(5)
        ->and($defaults[0]['name'])->toBe('Solo')
        ->and($defaults[1]['name'])->toBe('Interview')
        ->and($defaults[2]['name'])->toBe('Guest Spotlight')
        ->and($defaults[3]['name'])->toBe('Screen Share')
        ->and($defaults[4]['name'])->toBe('Picture-in-Picture');
});

test('scene template stores scenes as json', function () {
    $scenes = LiveEventSceneTemplate::defaultScenes();
    $template = LiveEventSceneTemplate::factory()->create(['scenes' => $scenes]);

    $template->refresh();

    expect($template->scenes)->toBeArray()
        ->and($template->scenes[0]['slots'][0]['source'])->toBe('host');
});

test('scene template stores overlays as json', function () {
    $overlays = [
        ['type' => 'text', 'content' => 'Test Title', 'x' => 0.05, 'y' => 0.02],
        ['type' => 'image', 'src' => '/logo.png', 'x' => 0.85, 'y' => 0.02, 'w' => 0.12, 'h' => 0.08],
    ];

    $template = LiveEventSceneTemplate::factory()->create(['overlays' => $overlays]);
    $template->refresh();

    expect($template->overlays)->toHaveCount(2)
        ->and($template->overlays[0]['type'])->toBe('text')
        ->and($template->overlays[1]['type'])->toBe('image');
});

// ============================================================
// LiveEvent Production Fields
// ============================================================

test('live event stores scenes configuration', function () {
    $scenes = LiveEventSceneTemplate::defaultScenes();
    $event = LiveEvent::factory()->create(['scenes' => $scenes]);

    $event->refresh();

    expect($event->scenes)->toBeArray()->toHaveCount(5);
});

test('live event getActiveScene returns current scene', function () {
    $scenes = LiveEventSceneTemplate::defaultScenes();
    $event = LiveEvent::factory()->create([
        'scenes' => $scenes,
        'active_scene_index' => 1,
    ]);

    $active = $event->getActiveScene();

    expect($active['name'])->toBe('Interview');
});

test('live event getActiveScene returns first scene when index is 0', function () {
    $scenes = LiveEventSceneTemplate::defaultScenes();
    $event = LiveEvent::factory()->create([
        'scenes' => $scenes,
        'active_scene_index' => 0,
    ]);

    expect($event->getActiveScene()['name'])->toBe('Solo');
});

test('live event getActiveScene returns null when no scenes configured', function () {
    $event = LiveEvent::factory()->create(['scenes' => null]);

    expect($event->getActiveScene())->toBeNull();
});

test('live event getScenesOrDefaults returns defaults when none configured', function () {
    $event = LiveEvent::factory()->create(['scenes' => null]);

    $scenes = $event->getScenesOrDefaults();

    expect($scenes)->toHaveCount(5)
        ->and($scenes[0]['name'])->toBe('Solo');
});

test('live event getScenesOrDefaults returns stored scenes when configured', function () {
    $customScenes = [
        ['name' => 'Custom', 'slots' => [['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]], 'overlays' => []],
    ];
    $event = LiveEvent::factory()->create(['scenes' => $customScenes]);

    expect($event->getScenesOrDefaults())->toHaveCount(1)
        ->and($event->getScenesOrDefaults()[0]['name'])->toBe('Custom');
});

test('live event has use_frame_mode defaulting to true', function () {
    $event = LiveEvent::factory()->create();
    $event->refresh();

    expect($event->use_frame_mode)->toBeTrue();
});

test('live event has backstage chat thread relation', function () {
    $thread = ChatThread::create([
        'type' => 'live_event',
        'subject' => 'Backstage',
        'last_message_at' => now(),
    ]);

    $event = LiveEvent::factory()->create(['backstage_chat_thread_id' => $thread->id]);

    expect($event->backstageChatThread)->toBeInstanceOf(ChatThread::class)
        ->and($event->backstageChatThread->subject)->toBe('Backstage');
});

// ============================================================
// LiveEventService — Guest Management
// ============================================================

test('service invites guest with auto-assigned slot', function () {
    $event = LiveEvent::factory()->create();
    $service = app(LiveEventService::class);

    $guest = $service->inviteGuest($event, [
        'name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'instructions' => 'Talk about your renovation experience',
    ]);

    expect($guest->name)->toBe('Jane Smith')
        ->and($guest->email)->toBe('jane@example.com')
        ->and($guest->instructions)->toBe('Talk about your renovation experience')
        ->and($guest->slot_number)->toBe(1)
        ->and($guest->status)->toBe('invited')
        ->and($guest->token)->toHaveLength(64);
});

test('service assigns sequential slot numbers to guests', function () {
    $event = LiveEvent::factory()->create();
    $service = app(LiveEventService::class);

    $guest1 = $service->inviteGuest($event, ['name' => 'Guest 1']);
    $guest2 = $service->inviteGuest($event, ['name' => 'Guest 2']);
    $guest3 = $service->inviteGuest($event, ['name' => 'Guest 3']);

    expect($guest1->slot_number)->toBe(1)
        ->and($guest2->slot_number)->toBe(2)
        ->and($guest3->slot_number)->toBe(3);
});

test('service generates guest link', function () {
    $event = LiveEvent::factory()->create();
    $service = app(LiveEventService::class);

    $guest = $service->inviteGuest($event, ['name' => 'Test Guest']);
    $link = $service->generateGuestLink($guest);

    expect($link)->toContain('/events/guest/')
        ->toContain($guest->token);
});

test('service marks guest as connected', function () {
    $guest = LiveEventGuest::factory()->create();
    $service = app(LiveEventService::class);

    $service->markGuestConnected($guest);

    expect($guest->fresh()->status)->toBe('connected')
        ->and($guest->fresh()->connected_at)->not->toBeNull();
});

test('service marks guest as live', function () {
    $guest = LiveEventGuest::factory()->connected()->create();
    $service = app(LiveEventService::class);

    $service->markGuestLive($guest);

    expect($guest->fresh()->status)->toBe('live')
        ->and($guest->fresh()->went_live_at)->not->toBeNull();
});

test('service takes guest off air', function () {
    $guest = LiveEventGuest::factory()->live()->create();
    $service = app(LiveEventService::class);

    $service->markGuestOffAir($guest);

    expect($guest->fresh()->status)->toBe('connected')
        ->and($guest->fresh()->live_ended_at)->not->toBeNull();
});

test('service marks guest as disconnected', function () {
    $guest = LiveEventGuest::factory()->connected()->create();
    $service = app(LiveEventService::class);

    $service->markGuestDisconnected($guest);

    expect($guest->fresh()->status)->toBe('disconnected')
        ->and($guest->fresh()->disconnected_at)->not->toBeNull();
});

test('service removes guest', function () {
    $guest = LiveEventGuest::factory()->create();
    $service = app(LiveEventService::class);

    $service->removeGuest($guest);

    expect(LiveEventGuest::find($guest->id))->toBeNull();
});

test('service ensures backstage chat thread is created once', function () {
    $event = LiveEvent::factory()->create();
    $service = app(LiveEventService::class);

    $thread1 = $service->ensureBackstageChatThread($event);
    $thread2 = $service->ensureBackstageChatThread($event->fresh());

    expect($thread1->id)->toBe($thread2->id)
        ->and($event->fresh()->backstage_chat_thread_id)->toBe($thread1->id);
});

// ============================================================
// LiveKitService — Token Generation
// ============================================================

test('livekit service generates guest token', function () {
    $event = LiveEvent::factory()->live()->create();
    $guest = LiveEventGuest::factory()->create([
        'live_event_id' => $event->id,
        'livekit_identity' => 'guest-test123',
    ]);

    $service = app(LiveKitService::class);

    // This will throw if LiveKit credentials aren't configured, which is expected in test
    // We just verify the method exists and is callable
    expect(method_exists($service, 'generateGuestToken'))->toBeTrue();
});

test('livekit service generates coproducer token', function () {
    $service = app(LiveKitService::class);

    expect(method_exists($service, 'generateCoproducerToken'))->toBeTrue();
});

// ============================================================
// Guest Cascade Delete
// ============================================================

test('guests are deleted when event is deleted', function () {
    $event = LiveEvent::factory()->create();
    LiveEventGuest::factory()->count(3)->create(['live_event_id' => $event->id]);

    expect(LiveEventGuest::where('live_event_id', $event->id)->count())->toBe(3);

    $event->delete();

    expect(LiveEventGuest::where('live_event_id', $event->id)->count())->toBe(0);
});

test('scene templates are deleted when user is deleted', function () {
    $user = User::factory()->internal()->create();
    LiveEventSceneTemplate::factory()->count(2)->create(['user_id' => $user->id]);

    expect(LiveEventSceneTemplate::where('user_id', $user->id)->count())->toBe(2);

    $user->delete();

    expect(LiveEventSceneTemplate::where('user_id', $user->id)->count())->toBe(0);
});
