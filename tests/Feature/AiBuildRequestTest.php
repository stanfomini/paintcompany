<?php

use App\Models\AiBuildRequest;
use App\Models\Estimate;

it('creates an ai build request for an estimate', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    expect($request->estimate->id)->toBe($estimate->id);
    expect($request->isActive())->toBeTrue();
});

it('tracks build lifecycle', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'pending',
    ]);

    $request->markBuilding();
    expect($request->fresh()->status)->toBe('building');
    expect($request->fresh()->started_at)->not->toBeNull();

    $request->markCompleted(25, 89, 'Built 25 areas with 89 surfaces');
    expect($request->fresh()->status)->toBe('completed');
    expect($request->fresh()->areas_created)->toBe(25);
    expect($request->fresh()->surfaces_created)->toBe(89);
});

it('marks failures with status and message', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $request->markFailed('nothing_to_build', 'No blueprints, photos, or notes found.');
    expect($request->fresh()->status)->toBe('nothing_to_build');
    expect($request->fresh()->isActive())->toBeFalse();
});
