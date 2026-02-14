<?php

use App\Events\AiBuildCompleted;
use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('completes full trigger-to-callback flow', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();

    // 1. Trigger the build
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $triggerResponse->assertOk();
    $requestId = $triggerResponse->json('request_id');

    // 2. Verify build request was created
    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest)->not->toBeNull();
    expect($buildRequest->status)->toBe('pending');

    // 3. Check status endpoint returns correct state
    $this->getJson("/webhooks/ai-build/{$requestId}", [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertOk()
        ->assertJson(['status' => 'pending']);

    // 4. Simulate callback from Claude Code
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'completed',
        'areas_created' => 15,
        'surfaces_created' => 60,
        'summary' => 'Built 15 areas with 60 surfaces from blueprint PDF.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // 5. Verify build request updated
    $buildRequest->refresh();
    expect($buildRequest->status)->toBe('completed');
    expect($buildRequest->areas_created)->toBe(15);
    expect($buildRequest->surfaces_created)->toBe(60);
    expect($buildRequest->summary)->toBe('Built 15 areas with 60 surfaces from blueprint PDF.');
    expect($buildRequest->completed_at)->not->toBeNull();
    expect($buildRequest->callback_sent_at)->not->toBeNull();

    // 6. Verify broadcast sent
    Event::assertDispatched(AiBuildCompleted::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id
            && $event->status === 'completed'
            && $event->areasCreated === 15
            && $event->surfacesCreated === 60;
    });

    // 7. Status endpoint now reflects completion
    $this->getJson("/webhooks/ai-build/{$requestId}", [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertOk()
        ->assertJson([
            'status' => 'completed',
            'areas_created' => 15,
            'surfaces_created' => 60,
        ]);
});

it('handles nothing-to-build gracefully', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $triggerResponse->assertOk();
    $requestId = $triggerResponse->json('request_id');

    // Simulate failure callback
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'nothing_to_build',
        'error_message' => 'No blueprints, photos, or notes found.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest->status)->toBe('nothing_to_build');
    expect($buildRequest->error_message)->toContain('No blueprints');
    expect($buildRequest->areas_created)->toBe(0);
    expect($buildRequest->completed_at)->not->toBeNull();

    Event::assertDispatched(AiBuildCompleted::class, function ($event) {
        return $event->status === 'nothing_to_build';
    });
});

it('handles partial build with some areas created', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $requestId = $triggerResponse->json('request_id');

    // Simulate partial build callback
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'partial',
        'areas_created' => 5,
        'surfaces_created' => 18,
        'summary' => 'Built 5 of 12 areas. Some blueprints were unreadable.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest->status)->toBe('completed');
    expect($buildRequest->areas_created)->toBe(5);
    expect($buildRequest->surfaces_created)->toBe(18);

    Event::assertDispatched(AiBuildCompleted::class, function ($event) {
        return $event->status === 'partial'
            && $event->areasCreated === 5;
    });
});

it('handles unreadable blueprints failure', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->once();

    $triggerResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $requestId = $triggerResponse->json('request_id');

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $requestId,
        'status' => 'unreadable_blueprints',
        'error_message' => 'PDF text extraction failed. Blueprints appear to be scanned images.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    $buildRequest = AiBuildRequest::find($requestId);
    expect($buildRequest->status)->toBe('unreadable_blueprints');
    expect($buildRequest->error_message)->toContain('scanned images');
    expect($buildRequest->isActive())->toBeFalse();

    Event::assertDispatched(AiBuildCompleted::class, function ($event) {
        return $event->status === 'unreadable_blueprints'
            && $event->errorMessage !== null;
    });
});

it('prevents second build while first is active', function () {
    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->once();

    // First trigger succeeds
    $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // Second trigger for same estimate is rejected
    $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertStatus(409)
        ->assertJsonStructure(['error', 'request_id', 'status']);
});

it('allows new build after previous one completed', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    Artisan::shouldReceive('call')->twice();

    // First build trigger
    $firstResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $firstRequestId = $firstResponse->json('request_id');

    // Complete the first build via callback
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $firstRequestId,
        'status' => 'completed',
        'areas_created' => 10,
        'surfaces_created' => 40,
        'summary' => 'First build complete.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // Second build trigger should now succeed
    $secondResponse = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], ['X-AI-Build-Secret' => 'test-secret-123']);

    $secondResponse->assertOk();
    $secondRequestId = $secondResponse->json('request_id');

    expect($secondRequestId)->not->toBe($firstRequestId);
    expect(AiBuildRequest::where('estimate_id', $estimate->id)->count())->toBe(2);
});

it('handles callback for unknown request id gracefully', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();

    // Send callback with a request_id that doesn't exist
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => 99999,
        'status' => 'completed',
        'areas_created' => 5,
        'surfaces_created' => 20,
        'summary' => 'Built 5 areas.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // Event should still be dispatched even if request record is missing
    Event::assertDispatched(AiBuildCompleted::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id
            && $event->status === 'completed';
    });
});

it('broadcasts correct data shape in AiBuildCompleted event', function () {
    $event = new AiBuildCompleted(
        estimateId: 42,
        status: 'completed',
        areasCreated: 10,
        surfacesCreated: 35,
        summary: 'Built 10 areas from blueprints.',
        errorMessage: null,
    );

    expect($event->broadcastAs())->toBe('ai.build.completed');
    expect($event->broadcastOn())->toHaveCount(1);
    expect($event->broadcastWith())->toBe([
        'estimate_id' => 42,
        'status' => 'completed',
        'areas_created' => 10,
        'surfaces_created' => 35,
        'summary' => 'Built 10 areas from blueprints.',
        'error_message' => null,
    ]);
});
