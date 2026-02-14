<?php

use App\Models\AiBuildRequest;
use App\Models\Estimate;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret-123']);
});

it('rejects requests without valid api secret', function () {
    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1])
        ->assertUnauthorized();

    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1], [
        'X-AI-Build-Secret' => 'wrong-secret',
    ])->assertUnauthorized();
});

it('rejects requests when ai build is disabled', function () {
    config(['ai-build.enabled' => false]);

    $this->postJson('/webhooks/ai-build', ['estimate_id' => 1], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertStatus(503);
});

it('creates a build request and returns building status', function () {
    $estimate = Estimate::factory()->create();

    Artisan::shouldReceive('call')
        ->once()
        ->with('estimate:ai-build', \Mockery::type('array'));

    $response = $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ]);

    $response->assertOk()
        ->assertJson(['status' => 'building']);

    expect(AiBuildRequest::where('estimate_id', $estimate->id)->exists())->toBeTrue();
});

it('prevents duplicate active builds', function () {
    $estimate = Estimate::factory()->create();
    AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'building',
    ]);

    $this->postJson('/webhooks/ai-build', [
        'estimate_id' => $estimate->id,
    ], [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertStatus(409);
});

it('returns build request status', function () {
    $estimate = Estimate::factory()->create();
    $request = AiBuildRequest::create([
        'estimate_id' => $estimate->id,
        'status' => 'completed',
        'areas_created' => 25,
        'surfaces_created' => 89,
        'summary' => 'Built 25 areas',
    ]);

    $this->getJson("/webhooks/ai-build/{$request->id}", [
        'X-AI-Build-Secret' => 'test-secret-123',
    ])->assertOk()
        ->assertJson([
            'status' => 'completed',
            'areas_created' => 25,
            'surfaces_created' => 89,
        ]);
});
