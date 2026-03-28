<?php

use App\Models\ChatThread;
use App\Models\LiveEvent;
use App\Models\ServiceArea;
use App\Services\LiveEventService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('live events listing page renders', function () {
    $this->get(route('live.index'))
        ->assertOk()
        ->assertSee('Live Painting Events');
});

test('live events listing shows live events', function () {
    LiveEvent::factory()->live()->create(['title' => 'Painting Demo Live Now']);

    $this->get(route('live.index'))
        ->assertOk()
        ->assertSee('Painting Demo Live Now')
        ->assertSee('Live Now');
});

test('live events listing shows upcoming events', function () {
    LiveEvent::factory()->scheduled()->create([
        'title' => 'Upcoming Painting Workshop',
        'scheduled_at' => now()->addDays(3),
    ]);

    $this->get(route('live.index'))
        ->assertOk()
        ->assertSee('Upcoming Painting Workshop')
        ->assertSee('Upcoming Events');
});

test('live events listing shows past events', function () {
    LiveEvent::factory()->ended()->create(['title' => 'Past Painting Session']);

    $this->get(route('live.index'))
        ->assertOk()
        ->assertSee('Past Painting Session')
        ->assertSee('Past Events');
});

test('live events listing filters by service area', function () {
    $area = ServiceArea::factory()->create(['is_active' => true]);
    $otherArea = ServiceArea::factory()->create(['is_active' => true]);

    LiveEvent::factory()->live()->create([
        'title' => 'Denver Painting Demo',
        'service_area_id' => $area->id,
    ]);
    LiveEvent::factory()->live()->create([
        'title' => 'Boulder Area Event',
        'service_area_id' => $otherArea->id,
    ]);

    \Livewire\Livewire::test('public.live-events')
        ->set('selectedArea', (string) $area->id)
        ->assertSee('Denver Painting Demo')
        ->assertDontSee('Boulder Area Event');
});

test('live event show page renders for scheduled event', function () {
    $event = LiveEvent::factory()->scheduled()->create([
        'title' => 'Upcoming Workshop',
        'scheduled_at' => now()->addDays(5),
    ]);

    $this->get(route('live.show', $event->slug))
        ->assertOk()
        ->assertSee('Upcoming Workshop')
        ->assertSee('Upcoming Event');
});

test('live event show page renders for live event', function () {
    $event = LiveEvent::factory()->live()->create(['title' => 'Live Demo In Progress']);

    $this->get(route('live.show', $event->slug))
        ->assertOk()
        ->assertSee('Live Demo In Progress')
        ->assertSee('Join Stream');
});

test('live event show page renders for ended event', function () {
    $event = LiveEvent::factory()->ended()->create(['title' => 'Finished Workshop']);

    $this->get(route('live.show', $event->slug))
        ->assertOk()
        ->assertSee('Finished Workshop')
        ->assertSee('Event Ended');
});

test('live event show page renders for cancelled event', function () {
    $event = LiveEvent::factory()->cancelled()->create(['title' => 'Cancelled Event']);

    $this->get(route('live.show', $event->slug))
        ->assertOk()
        ->assertSee('Event Unavailable');
});

test('live event registration works for scheduled event', function () {
    $event = LiveEvent::factory()->registration()->scheduled()->create([
        'scheduled_at' => now()->addDays(5),
    ]);

    $mockService = Mockery::mock(LiveEventService::class);
    $mockService->shouldReceive('registerViewer')
        ->once()
        ->andReturn(\App\Models\LiveEventRegistration::factory()->make([
            'live_event_id' => $event->id,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]));
    app()->instance(LiveEventService::class, $mockService);

    \Livewire\Livewire::test('public.live-event', ['liveEvent' => $event])
        ->set('viewerName', 'Jane Doe')
        ->set('viewerEmail', 'jane@example.com')
        ->set('viewerPhone', '720-555-0100')
        ->call('registerForEvent')
        ->assertHasNoErrors();
});

test('live event join stream requires name', function () {
    $event = LiveEvent::factory()->live()->public()->create();

    \Livewire\Livewire::test('public.live-event', ['liveEvent' => $event])
        ->set('viewerName', '')
        ->call('joinStream')
        ->assertHasErrors(['viewerName']);
});

test('live event question submission works', function () {
    $event = LiveEvent::factory()->live()->create();

    $mockService = Mockery::mock(LiveEventService::class);
    $mockService->shouldReceive('submitQuestion')
        ->once()
        ->withArgs(function ($e, $question, $name) use ($event) {
            return $e->id === $event->id
                && $question === 'What paint brand do you recommend?'
                && $name === 'John';
        })
        ->andReturn(\App\Models\LiveEventQuestion::factory()->make([
            'live_event_id' => $event->id,
            'question' => 'What paint brand do you recommend?',
            'viewer_name' => 'John',
            'status' => 'pending',
        ]));
    app()->instance(LiveEventService::class, $mockService);

    \Livewire\Livewire::test('public.live-event', ['liveEvent' => $event])
        ->set('viewerName', 'John')
        ->set('questionText', 'What paint brand do you recommend?')
        ->call('submitQuestion')
        ->assertHasNoErrors()
        ->assertSet('questionText', '');
});

test('live event chat message works', function () {
    $chatThread = ChatThread::factory()->create(['type' => 'live_event']);
    $event = LiveEvent::factory()->live()->create(['chat_thread_id' => $chatThread->id]);

    \Livewire\Livewire::test('public.live-event', ['liveEvent' => $event])
        ->set('viewerName', 'Alice')
        ->set('chatMessage', 'Hello everyone!')
        ->call('sendChatMessage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('chat_messages', [
        'thread_id' => $chatThread->id,
        'content' => 'Alice: Hello everyone!',
    ]);
});

test('live events listing page has structured data', function () {
    LiveEvent::factory()->scheduled()->create([
        'scheduled_at' => now()->addDays(5),
    ]);

    $this->get(route('live.index'))
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('schema.org', false);
});

test('live event show page has structured data', function () {
    $event = LiveEvent::factory()->scheduled()->create([
        'scheduled_at' => now()->addDays(5),
    ]);

    $this->get(route('live.show', $event->slug))
        ->assertOk()
        ->assertSee('application/ld+json', false)
        ->assertSee('schema.org', false);
});
