<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateTemplateUsage;
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

    // AI build now lives in the ai-builder child component
    Livewire::actingAs($user)
        ->test('estimates.ai-builder', ['estimate' => $estimate])
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

    // AI build now lives in the ai-builder child component
    Livewire::actingAs($user)
        ->test('estimates.ai-builder', ['estimate' => $estimate])
        ->call('triggerAiBuild')
        ->assertSet('aiBuildInProgress', true)
        ->assertSet('aiBuildStatus', 'building');

    Http::assertSent(function ($request) use ($estimate) {
        return $request->url() === 'https://apexnegotiations.com/webhooks/ai-build'
            && $request['estimate_id'] === $estimate->id;
    });
});

it('loads AI-built areas with template usages in template builder', function () {
    CompanySetting::create([
        'company_name' => 'Test Company',
        'company_email' => 'test@example.com',
    ]);

    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

    // Simulate what the AI builder creates: area + surfaces + template usage with null template_id
    $area = EstimateArea::create([
        'estimate_id' => $estimate->id,
        'name' => 'AI Living Room',
        'scope_type' => 'interior',
        'length' => 20,
        'width' => 15,
        'height' => 9,
        'wall_count' => 4,
    ]);

    $area->surfaces()->create([
        'name' => 'Walls',
        'surface_type' => 'walls',
        'quantity' => $area->wall_sqft,
        'unit' => 'sqft',
        'production_rate_id' => null,
        'production_rate_value' => 120,
        'hourly_labor_rate' => 30,
        'coats' => 2,
        'is_included' => true,
        'sort_order' => 1,
    ]);

    // Create template usage with null template_id (AI-built)
    EstimateTemplateUsage::create([
        'estimate_id' => $estimate->id,
        'template_id' => null,
        'resultable_type' => EstimateArea::class,
        'resultable_id' => $area->id,
        'quantity' => $area->wall_sqft,
        'coats' => 2,
        'calculated_price' => 100,
        'sort_order' => 1,
    ]);

    $component = Livewire::actingAs($user)
        ->test('estimates.template-builder', ['estimate' => $estimate]);

    // The AI-built area should appear in selectedTemplates
    $selectedTemplates = $component->get('selectedTemplates');

    expect($selectedTemplates)->toHaveCount(1);
    expect($selectedTemplates[0]['name'])->toBe('AI Living Room');
    expect($selectedTemplates[0]['template_id'])->toBeNull();
    expect($selectedTemplates[0]['area_data'])->not->toBeNull();
    expect((float) $selectedTemplates[0]['area_data']['length'])->toBe(20.0);
    expect((float) $selectedTemplates[0]['area_data']['width'])->toBe(15.0);
});
