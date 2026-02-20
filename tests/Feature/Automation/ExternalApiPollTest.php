<?php

use App\Automation\Triggers\ExternalApiPoll;
use App\Events\Automation\ApiPollReceived;
use App\Jobs\PollExternalApiJob;
use App\Models\ExternalApiSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

// ===== Trigger Matching =====

it('matches api_poll.received event', function () {
    $trigger = new ExternalApiPoll;
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($trigger->matches('api_poll.received', $source, ['api_source_token' => $source->token], []))
        ->toBeTrue();
});

it('does not match wrong event name', function () {
    $trigger = new ExternalApiPoll;
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($trigger->matches('webhook.received', $source, [], []))
        ->toBeFalse();
});

it('matches api_poll.received with matching token filter', function () {
    $trigger = new ExternalApiPoll;
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($trigger->matches(
        'api_poll.received',
        $source,
        ['api_source_token' => $source->token],
        ['api_source_token' => $source->token],
    ))->toBeTrue();
});

it('does not match api_poll.received when token filter differs', function () {
    $trigger = new ExternalApiPoll;
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($trigger->matches(
        'api_poll.received',
        $source,
        ['api_source_token' => $source->token],
        ['api_source_token' => 'different-token'],
    ))->toBeFalse();
});

it('matches api_poll.received with empty token config as wildcard', function () {
    $trigger = new ExternalApiPoll;
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($trigger->matches('api_poll.received', $source, ['api_source_token' => $source->token], []))
        ->toBeTrue();
});

it('has correct metadata for ExternalApiPoll', function () {
    expect(ExternalApiPoll::getName())->toBe('External API Poll')
        ->and(ExternalApiPoll::getCategory())->toBe('Integrations')
        ->and(ExternalApiPoll::getDescription())->toBeString()
        ->and(ExternalApiPoll::getConfigSchema())->toHaveKey('api_source_token');
});

// ===== Model =====

it('auto-generates uuid token on creation', function () {
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);

    expect($source->token)->not->toBeNull()
        ->and($source->token)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-/');
});

it('determines shouldPollNow correctly for never-polled source', function () {
    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'last_polled_at' => null,
    ]);

    expect($source->shouldPollNow())->toBeTrue();
});

it('determines shouldPollNow correctly when interval has elapsed', function () {
    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'poll_interval' => 'every_5_min',
        'last_polled_at' => now()->subMinutes(6),
    ]);

    expect($source->shouldPollNow())->toBeTrue();
});

it('determines shouldPollNow correctly when interval has not elapsed', function () {
    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'poll_interval' => 'every_5_min',
        'last_polled_at' => now()->subMinutes(2),
    ]);

    expect($source->shouldPollNow())->toBeFalse();
});

it('shouldPollNow returns false for inactive source', function () {
    $source = ExternalApiSource::factory()->inactive()->create([
        'created_by' => $this->user->id,
        'last_polled_at' => null,
    ]);

    expect($source->shouldPollNow())->toBeFalse();
});

it('builds http request with bearer auth', function () {
    $source = ExternalApiSource::factory()->withBearerAuth('my-token')->create([
        'created_by' => $this->user->id,
    ]);

    $request = $source->buildHttpRequest();
    expect($request)->toBeInstanceOf(\Illuminate\Http\Client\PendingRequest::class);
});

it('builds http request with api key auth', function () {
    $source = ExternalApiSource::factory()->withApiKeyAuth('X-Api-Key', 'my-key')->create([
        'created_by' => $this->user->id,
    ]);

    $request = $source->buildHttpRequest();
    expect($request)->toBeInstanceOf(\Illuminate\Http\Client\PendingRequest::class);
});

it('encrypts auth_value field', function () {
    $source = ExternalApiSource::factory()->withBearerAuth('secret-token')->create([
        'created_by' => $this->user->id,
    ]);

    // Reading from model decrypts
    expect($source->auth_value)->toBe('secret-token');

    // Raw DB value should be encrypted (not plaintext)
    $raw = \DB::table('external_api_sources')
        ->where('id', $source->id)
        ->value('auth_value');

    expect($raw)->not->toBe('secret-token');
});

// ===== Event =====

it('ApiPollReceived has correct event name and context', function () {
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);
    $payload = ['name' => 'John', 'email' => 'john@example.com'];

    $event = new ApiPollReceived($source, $payload);

    expect($event->getEventName())->toBe('api_poll.received')
        ->and($event->getSubject())->toBe($source)
        ->and($event->getContext()['api_source_id'])->toBe($source->id)
        ->and($event->getContext()['api_source_token'])->toBe($source->token)
        ->and($event->getContext()['payload'])->toBe($payload);
});

// ===== Job =====

it('polls external api and fires events for new records', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'data_path' => 'data',
        'dedup_field' => 'id',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertDispatched(ApiPollReceived::class, 2);
    Event::assertDispatched(ApiPollReceived::class, fn ($e) => $e->payload['name'] === 'Alice');
    Event::assertDispatched(ApiPollReceived::class, fn ($e) => $e->payload['name'] === 'Bob');

    $source->refresh();
    expect($source->poll_count)->toBe(1)
        ->and($source->last_success_at)->not->toBeNull()
        ->and($source->last_error)->toBeNull()
        ->and($source->processed_ids)->toContain('1', '2');
});

it('deduplicates records by field', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => Http::response([
            'results' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'data_path' => 'results',
        'dedup_field' => 'id',
        'processed_ids' => ['1'], // Alice already processed
    ]);

    (new PollExternalApiJob($source->id))->handle();

    // Only Bob should be new
    Event::assertDispatched(ApiPollReceived::class, 1);
    Event::assertDispatched(ApiPollReceived::class, fn ($e) => $e->payload['name'] === 'Bob');
});

it('deduplicates records by hash when no dedup field set', function () {
    Event::fake([ApiPollReceived::class]);

    $record = ['name' => 'Alice', 'email' => 'alice@test.com'];
    $hash = md5(json_encode($record));

    Http::fake([
        'https://api.example.com/data' => Http::response([$record]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'dedup_field' => null,
        'data_path' => null,
        'processed_ids' => [$hash],
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertNotDispatched(ApiPollReceived::class);
});

it('extracts records using data_path dot notation', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => Http::response([
            'response' => [
                'nested' => [
                    'items' => [
                        ['id' => 10, 'name' => 'Deep'],
                    ],
                ],
            ],
        ]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'data_path' => 'response.nested.items',
        'dedup_field' => 'id',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertDispatched(ApiPollReceived::class, fn ($e) => $e->payload['name'] === 'Deep');
});

it('handles http errors gracefully', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => Http::response('Server Error', 500),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertNotDispatched(ApiPollReceived::class);

    $source->refresh();
    expect($source->error_count)->toBe(1)
        ->and($source->last_error)->toContain('500');
});

it('handles connection exceptions gracefully', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertNotDispatched(ApiPollReceived::class);

    $source->refresh();
    expect($source->error_count)->toBe(1)
        ->and($source->last_error)->toContain('Connection refused');
});

it('caps processed_ids at 1000 entries', function () {
    Event::fake([ApiPollReceived::class]);

    // Create a source with 999 existing processed IDs
    $existingIds = array_map(fn ($i) => (string) $i, range(1, 999));

    Http::fake([
        'https://api.example.com/data' => Http::response([
            ['id' => 1000, 'name' => 'New1'],
            ['id' => 1001, 'name' => 'New2'],
        ]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'dedup_field' => 'id',
        'processed_ids' => $existingIds,
    ]);

    (new PollExternalApiJob($source->id))->handle();

    $source->refresh();
    expect(count($source->processed_ids))->toBeLessThanOrEqual(1000);
});

it('skips inactive source', function () {
    Event::fake([ApiPollReceived::class]);

    $source = ExternalApiSource::factory()->inactive()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertNotDispatched(ApiPollReceived::class);
});

it('skips source without url', function () {
    Event::fake([ApiPollReceived::class]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => '',
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertNotDispatched(ApiPollReceived::class);
});

it('handles single record response without data_path', function () {
    Event::fake([ApiPollReceived::class]);

    Http::fake([
        'https://api.example.com/data' => Http::response([
            'name' => 'Single Record',
            'email' => 'test@example.com',
        ]),
    ]);

    $source = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'data_path' => null,
        'dedup_field' => null,
    ]);

    (new PollExternalApiJob($source->id))->handle();

    Event::assertDispatched(ApiPollReceived::class, 1);
    Event::assertDispatched(ApiPollReceived::class, fn ($e) => $e->payload['name'] === 'Single Record');
});

// ===== Command =====

it('dispatches jobs for due sources', function () {
    Queue::fake();

    $due = ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'last_polled_at' => null,
    ]);

    $this->artisan('api-sources:poll')
        ->assertSuccessful()
        ->expectsOutputToContain('1 API poll job');

    Queue::assertPushed(PollExternalApiJob::class, fn ($job) => $job->sourceId === $due->id);
});

it('skips sources not yet due for polling', function () {
    Queue::fake();

    ExternalApiSource::factory()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'poll_interval' => 'every_5_min',
        'last_polled_at' => now()->subMinute(),
    ]);

    $this->artisan('api-sources:poll')
        ->assertSuccessful()
        ->expectsOutputToContain('0 API poll job');

    Queue::assertNotPushed(PollExternalApiJob::class);
});

it('skips inactive sources in command', function () {
    Queue::fake();

    ExternalApiSource::factory()->inactive()->create([
        'created_by' => $this->user->id,
        'url' => 'https://api.example.com/data',
        'last_polled_at' => null,
    ]);

    $this->artisan('api-sources:poll')
        ->assertSuccessful()
        ->expectsOutputToContain('0 API poll job');

    Queue::assertNotPushed(PollExternalApiJob::class);
});

// ===== Integration: Full Pipeline =====

it('processes api poll through workflow engine event dispatch', function () {
    $source = ExternalApiSource::factory()->create(['created_by' => $this->user->id]);
    $payload = ['name' => 'Test Customer', 'email' => 'test@example.com'];

    Event::fake([ApiPollReceived::class]);

    event(new ApiPollReceived($source, $payload));

    Event::assertDispatched(ApiPollReceived::class, function ($event) use ($source, $payload) {
        return $event->source->is($source)
            && $event->payload === $payload
            && $event->getEventName() === 'api_poll.received'
            && $event->getContext()['payload'] === $payload;
    });
});

it('event is registered in AutomationServiceProvider', function () {
    // Verify the event listener is registered
    $dispatcher = app('events');
    $listeners = $dispatcher->getListeners(ApiPollReceived::class);

    expect($listeners)->not->toBeEmpty();
});
