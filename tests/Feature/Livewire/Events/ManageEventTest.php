<?php

use App\Models\LiveEvent;
use App\Models\LiveEventQuestion;
use App\Models\LiveEventResource;
use App\Models\User;
use App\Services\LiveKitService;
use Livewire\Livewire;

beforeEach(function () {
    $this->internal = User::factory()->internal()->withFullAccess()->create();
    $this->event = LiveEvent::factory()->scheduled()->create([
        'host_user_id' => $this->internal->id,
    ]);

    // Mock LiveKitService since config values are not available in tests
    $mockLiveKit = Mockery::mock(LiveKitService::class);
    $mockLiveKit->shouldReceive('createRoom')->byDefault();
    $mockLiveKit->shouldReceive('deleteRoom')->byDefault();
    $mockLiveKit->shouldReceive('generateHostToken')->byDefault()->andReturn('mock-jwt-token');
    $mockLiveKit->shouldReceive('getParticipantCount')->byDefault()->andReturn(0);
    app()->instance(LiveKitService::class, $mockLiveKit);
    $this->mockLiveKit = $mockLiveKit;
});

describe('authorization', function () {
    it('blocks non-internal users via Livewire', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertForbidden();
    });

    it('allows internal users', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertOk();
    });
});

describe('pre-live state', function () {
    it('shows event title and scheduled time for scheduled events', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertSee($this->event->title)
            ->assertSee('Go Live');
    });

    it('shows event title for draft events', function () {
        $draft = LiveEvent::factory()->draft()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $draft])
            ->assertSee($draft->title)
            ->assertSee('Go Live');
    });

    it('shows registration count', function () {
        $this->event->registrations()->createMany([
            ['name' => 'A', 'email' => 'a@test.com'],
            ['name' => 'B', 'email' => 'b@test.com'],
            ['name' => 'C', 'email' => 'c@test.com'],
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->assertSee('3');
    });
});

describe('go live', function () {
    it('transitions event to live status', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('goLive')
            ->assertRedirect(route('events.manage', $this->event));

        expect($this->event->fresh()->status)->toBe('live');
    });

    it('prevents going live from ended status', function () {
        $ended = LiveEvent::factory()->ended()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $ended])
            ->call('goLive');

        expect($ended->fresh()->status)->toBe('ended');
    });
});

describe('end stream', function () {
    it('transitions event to ended status', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->call('endStream')
            ->assertDispatched('livekit-disconnect');

        expect($live->fresh()->status)->toBe('ended');
    });

    it('does nothing for non-live events', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('endStream');

        expect($this->event->fresh()->status)->toBe('scheduled');
    });
});

describe('question management', function () {
    it('answers a pending question', function () {
        $question = LiveEventQuestion::factory()->pending()->create([
            'live_event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('answerQuestion', $question->id);

        expect($question->fresh()->status)->toBe('answered');
        expect($question->fresh()->answered_at)->not->toBeNull();
    });

    it('dismisses a pending question', function () {
        $question = LiveEventQuestion::factory()->pending()->create([
            'live_event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('dismissQuestion', $question->id);

        expect($question->fresh()->status)->toBe('dismissed');
    });

    it('prevents answering questions from other events', function () {
        $otherEvent = LiveEvent::factory()->create();
        $question = LiveEventQuestion::factory()->pending()->create([
            'live_event_id' => $otherEvent->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('answerQuestion', $question->id);

        // Question should remain pending since findOrFail scoped to this event will throw
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

describe('resource management', function () {
    it('adds a resource', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('newResourceTitle', 'Test Resource')
            ->set('newResourceUrl', 'https://example.com')
            ->set('newResourceType', 'link')
            ->call('addResource');

        expect($this->event->resources()->count())->toBe(1);
        expect($this->event->resources()->first()->title)->toBe('Test Resource');
    });

    it('validates resource fields', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->set('newResourceTitle', '')
            ->set('newResourceUrl', 'not-a-url')
            ->call('addResource')
            ->assertHasErrors(['newResourceTitle', 'newResourceUrl']);
    });

    it('removes a resource', function () {
        $resource = LiveEventResource::factory()->create([
            'live_event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('removeResource', $resource->id);

        expect(LiveEventResource::find($resource->id))->toBeNull();
    });

    it('prevents removing resources from other events', function () {
        $otherEvent = LiveEvent::factory()->create();
        $resource = LiveEventResource::factory()->create([
            'live_event_id' => $otherEvent->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('removeResource', $resource->id);

        // Resource should still exist since it belongs to another event
        expect(LiveEventResource::find($resource->id))->not->toBeNull();
    });
});

describe('post-live state', function () {
    it('shows event summary for ended events', function () {
        $ended = LiveEvent::factory()->ended()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $ended])
            ->assertSee($ended->title)
            ->assertSee('Ended')
            ->assertSee('Duration')
            ->assertSee('Questions Answered')
            ->assertSee('Registrations');
    });

    it('shows cancelled state', function () {
        $cancelled = LiveEvent::factory()->cancelled()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $cancelled])
            ->assertSee('Event Cancelled')
            ->assertSee($cancelled->title);
    });
});

describe('live state display', function () {
    it('shows live badge and controls for live events', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->assertSee('LIVE')
            ->assertSee('End Stream')
            ->assertSee('Q&A Queue');
    });

    it('shows pending questions during live event', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        $question = LiveEventQuestion::factory()->pending()->create([
            'live_event_id' => $live->id,
            'viewer_name' => 'Jane Doe',
            'question' => 'How much does exterior painting cost?',
        ]);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->assertSee('Jane Doe')
            ->assertSee('How much does exterior painting cost?');
    });
});

describe('viewer count', function () {
    it('refreshes viewer count for live events', function () {
        $live = LiveEvent::factory()->live()->create([
            'host_user_id' => $this->internal->id,
        ]);

        // Override the default mock to return specific count
        $this->mockLiveKit->shouldReceive('getParticipantCount')->andReturn(5);

        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $live])
            ->call('refreshViewerCount')
            ->assertSet('viewerCount', 4); // 5 minus host
    });

    it('does not refresh viewer count for non-live events', function () {
        Livewire::actingAs($this->internal)
            ->test('pages::events.manage', ['liveEvent' => $this->event])
            ->call('refreshViewerCount')
            ->assertSet('viewerCount', 0);
    });
});
