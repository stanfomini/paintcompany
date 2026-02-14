<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['ai-build.enabled' => true]);
    config(['ai-build.api_secret' => 'test-secret']);
    config(['ai-build.dev_server_url' => 'https://apexnegotiations.com']);
});

it('shows AI build button when estimate has no areas', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->assertSee('AI Build from Blueprints');
});

it('shows AI build button when estimate has areas', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();
    $estimate->areas()->create([
        'name' => 'Test Area',
        'scope_type' => 'interior',
    ]);

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->assertSee('AI Rebuild from Blueprints');
});

it('sends webhook to dev server when AI build is triggered', function () {
    Http::fake([
        'apexnegotiations.com/*' => Http::response(['status' => 'building', 'request_id' => 1]),
    ]);

    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->call('triggerAiBuild')
        ->assertSet('aiBuildInProgress', true)
        ->assertSet('aiBuildStatus', 'building');

    Http::assertSent(function ($request) use ($estimate) {
        return $request->url() === 'https://apexnegotiations.com/webhooks/ai-build'
            && $request['estimate_id'] === $estimate->id;
    });
});

it('sends webhook even when estimate has existing areas', function () {
    Http::fake([
        'apexnegotiations.com/*' => Http::response(['status' => 'building', 'request_id' => 1]),
    ]);

    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create();
    $estimate->areas()->create([
        'name' => 'Existing Area',
        'scope_type' => 'interior',
    ]);

    Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate])
        ->call('triggerAiBuild')
        ->assertSet('aiBuildInProgress', true)
        ->assertSet('aiBuildStatus', 'building');

    Http::assertSent(function ($request) use ($estimate) {
        return $request->url() === 'https://apexnegotiations.com/webhooks/ai-build'
            && $request['estimate_id'] === $estimate->id;
    });
});
