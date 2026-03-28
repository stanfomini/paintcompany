<?php

use App\Models\LiveEvent;
use App\Models\LiveEventQuestion;
use App\Models\LiveEventRegistration;
use App\Models\LiveEventResource;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- LiveEvent model & factory ---

test('live event factory creates valid model', function () {
    $event = LiveEvent::factory()->create();

    expect($event)->toBeInstanceOf(LiveEvent::class)
        ->and($event->status)->toBe('scheduled')
        ->and($event->access_type)->toBe('public')
        ->and($event->max_viewers)->toBe(50)
        ->and($event->enable_recording)->toBeFalse()
        ->and($event->scheduled_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

test('live event factory states work correctly', function () {
    $draft = LiveEvent::factory()->draft()->create();
    $live = LiveEvent::factory()->live()->create();
    $ended = LiveEvent::factory()->ended()->create();
    $cancelled = LiveEvent::factory()->cancelled()->create();
    $registration = LiveEvent::factory()->registration()->create();

    expect($draft->status)->toBe('draft')
        ->and($live->status)->toBe('live')
        ->and($live->started_at)->not->toBeNull()
        ->and($ended->status)->toBe('ended')
        ->and($ended->started_at)->not->toBeNull()
        ->and($ended->ended_at)->not->toBeNull()
        ->and($cancelled->status)->toBe('cancelled')
        ->and($registration->access_type)->toBe('registration');
});

test('live event belongs to host user', function () {
    $user = User::factory()->internal()->create();
    $event = LiveEvent::factory()->create(['host_user_id' => $user->id]);

    expect($event->host->id)->toBe($user->id);
});

test('live event belongs to service area and service', function () {
    $area = ServiceArea::factory()->create();
    $service = Service::factory()->create();
    $event = LiveEvent::factory()->create([
        'service_area_id' => $area->id,
        'service_id' => $service->id,
    ]);

    expect($event->serviceArea->id)->toBe($area->id)
        ->and($event->service->id)->toBe($service->id);
});

test('live event has many resources', function () {
    $event = LiveEvent::factory()->create();
    LiveEventResource::factory()->count(3)->create(['live_event_id' => $event->id]);

    expect($event->resources)->toHaveCount(3);
});

test('live event has many questions', function () {
    $event = LiveEvent::factory()->create();
    LiveEventQuestion::factory()->count(2)->create(['live_event_id' => $event->id]);

    expect($event->questions)->toHaveCount(2);
});

test('live event has many registrations', function () {
    $event = LiveEvent::factory()->create();
    LiveEventRegistration::factory()->count(4)->create(['live_event_id' => $event->id]);

    expect($event->registrations)->toHaveCount(4);
});

test('live event uses slug for route model binding', function () {
    $event = LiveEvent::factory()->create();

    expect($event->getRouteKeyName())->toBe('slug');
});

// --- Scopes ---

test('upcoming scope filters scheduled future events', function () {
    LiveEvent::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(5)]);
    LiveEvent::factory()->create(['status' => 'draft', 'scheduled_at' => now()->addDays(5)]);
    LiveEvent::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->subDay()]);

    expect(LiveEvent::upcoming()->count())->toBe(1);
});

test('live scope filters live events', function () {
    LiveEvent::factory()->live()->create();
    LiveEvent::factory()->create();

    expect(LiveEvent::live()->count())->toBe(1);
});

test('ended scope filters ended events', function () {
    LiveEvent::factory()->ended()->create();
    LiveEvent::factory()->create();

    expect(LiveEvent::ended()->count())->toBe(1);
});

test('forArea scope filters by service area', function () {
    $area = ServiceArea::factory()->create();
    LiveEvent::factory()->create(['service_area_id' => $area->id]);
    LiveEvent::factory()->create();

    expect(LiveEvent::forArea($area->id)->count())->toBe(1);
});

test('forService scope filters by service', function () {
    $service = Service::factory()->create();
    LiveEvent::factory()->create(['service_id' => $service->id]);
    LiveEvent::factory()->create();

    expect(LiveEvent::forService($service->id)->count())->toBe(1);
});

// --- LiveEventQuestion scopes ---

test('question pending scope works', function () {
    $event = LiveEvent::factory()->create();
    LiveEventQuestion::factory()->pending()->create(['live_event_id' => $event->id]);
    LiveEventQuestion::factory()->answered()->create(['live_event_id' => $event->id]);
    LiveEventQuestion::factory()->dismissed()->create(['live_event_id' => $event->id]);

    expect(LiveEventQuestion::pending()->count())->toBe(1)
        ->and(LiveEventQuestion::answered()->count())->toBe(1)
        ->and(LiveEventQuestion::dismissed()->count())->toBe(1);
});

// --- LiveEventRegistration unique constraint ---

test('registration enforces unique email per event', function () {
    $event = LiveEvent::factory()->create();
    LiveEventRegistration::factory()->create([
        'live_event_id' => $event->id,
        'email' => 'test@example.com',
    ]);

    expect(fn () => LiveEventRegistration::factory()->create([
        'live_event_id' => $event->id,
        'email' => 'test@example.com',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('same email can register for different events', function () {
    $event1 = LiveEvent::factory()->create();
    $event2 = LiveEvent::factory()->create();

    LiveEventRegistration::factory()->create([
        'live_event_id' => $event1->id,
        'email' => 'test@example.com',
    ]);
    $reg2 = LiveEventRegistration::factory()->create([
        'live_event_id' => $event2->id,
        'email' => 'test@example.com',
    ]);

    expect($reg2)->toBeInstanceOf(LiveEventRegistration::class);
});

// --- Cascade deletes ---

test('deleting live event cascades to children', function () {
    $event = LiveEvent::factory()->create();
    LiveEventResource::factory()->count(2)->create(['live_event_id' => $event->id]);
    LiveEventQuestion::factory()->count(3)->create(['live_event_id' => $event->id]);
    LiveEventRegistration::factory()->count(4)->create(['live_event_id' => $event->id]);

    $event->delete();

    expect(LiveEventResource::count())->toBe(0)
        ->and(LiveEventQuestion::count())->toBe(0)
        ->and(LiveEventRegistration::count())->toBe(0);
});
