<?php

use App\Events\AiBuildCompleted;
use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('rejects callback without valid secret', function () {
    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => 1,
        'request_id' => 1,
        'status' => 'completed',
    ])->assertUnauthorized();
});

it('handles completed build callback and broadcasts event', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $request->id,
        'status' => 'completed',
        'areas_created' => 25,
        'surfaces_created' => 89,
        'summary' => 'Built 25 areas with 89 surfaces from blueprints.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    expect($request->fresh()->status)->toBe('completed');
    expect($request->fresh()->areas_created)->toBe(25);

    Event::assertDispatched(AiBuildCompleted::class, function ($event) use ($estimate) {
        return $event->estimateId === $estimate->id
            && $event->status === 'completed'
            && $event->areasCreated === 25;
    });
});

it('broadcasts progress update without changing build request status', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $request->id,
        'status' => 'building',
        'summary' => 'Extracting rooms from floor plan...',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    // Status should remain 'building', not changed to completed/failed
    expect($request->fresh()->status)->toBe('building');

    Event::assertDispatched(AiBuildCompleted::class, function ($event) {
        return $event->status === 'building'
            && $event->summary === 'Extracting rooms from floor plan...';
    });
});

it('handles failure callback', function () {
    Event::fake([AiBuildCompleted::class]);

    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build-callback', [
        'estimate_id' => $estimate->id,
        'request_id' => $request->id,
        'status' => 'nothing_to_build',
        'error_message' => 'No blueprints, photos, or notes found.',
    ], ['X-AI-Build-Secret' => 'test-secret-123'])
        ->assertOk();

    expect($request->fresh()->status)->toBe('nothing_to_build');
    Event::assertDispatched(AiBuildCompleted::class);
});
