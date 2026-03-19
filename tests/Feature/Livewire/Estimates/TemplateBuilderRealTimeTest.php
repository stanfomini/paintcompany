<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
    CompanySetting::create([
        'company_name' => 'Test Company',
        'company_email' => 'test@example.com',
    ]);
});

describe('Real-Time Echo Listeners', function () {
    it('returns correct Echo channel listeners for an existing estimate', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        $listeners = $component->instance()->getListeners();

        expect($listeners)->toHaveKey("echo-private:estimate.{$estimate->id},.estimate.ai.tool.completed");
        expect($listeners)->toHaveKey("echo-private:estimate.{$estimate->id},.estimate.area.updated");
        expect($listeners)->toHaveKey("echo-private:estimate.{$estimate->id},.ai.build.completed");
        expect($listeners)->toHaveKey('refreshEstimateFromDb');
    });

    it('returns minimal listeners when no estimate exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['deal' => $deal]);

        $listeners = $component->instance()->getListeners();

        expect($listeners)->toHaveKey('refreshEstimateFromDb');
        expect($listeners)->not->toHaveKey('echo-private:estimate.,.estimate.ai.tool.completed');
    });
});

describe('refreshEstimateFromDb', function () {
    it('rebuilds areas array from database', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 15,
            'width' => 12,
            'height' => 9,
            'wall_count' => 4,
        ]);

        $area->surfaces()->create([
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 400,
            'unit' => 'sqft',
            'production_rate_value' => 200,
            'hourly_labor_rate' => 30,
            'coats' => 2,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('refreshEstimateFromDb');

        $areas = $component->get('areas');

        expect($areas)->toHaveCount(1);
        expect($areas[0]['name'])->toBe('Living Room');
        expect($areas[0]['id'])->toBe($area->id);
    });

    it('dispatches areas-refreshed browser event', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('refreshEstimateFromDb')
            ->assertDispatched('areas-refreshed');
    });
});

describe('AI Tool Completed Handler', function () {
    it('updates progress label on ai tool completion', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('handleAiToolCompleted', ['tool_label' => 'Adding surfaces...', 'data_changed' => false])
            ->assertSet('aiProgressLabel', 'Adding surfaces...');
    });

    it('refreshes estimate when data changed', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        // Add area to DB so refresh finds it
        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'AI Room',
            'scope_type' => 'interior',
            'width' => 12,
            'height' => 9,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->assertSet('areas', [])
            ->call('handleAiToolCompleted', ['tool_label' => 'Created room', 'data_changed' => true]);

        $areas = $component->get('areas');
        expect($areas)->toHaveCount(1);
        expect($areas[0]['name'])->toBe('AI Room');
    });
});

describe('AI Build Completed Handler', function () {
    it('refreshes on completed status', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Blueprint Room',
            'scope_type' => 'interior',
            'width' => 10,
            'height' => 8,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('handleAiBuildCompleted', ['status' => 'completed']);

        expect($component->get('areas'))->toHaveCount(1);
    });

    it('does not refresh on failed status', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Should Not Appear',
            'scope_type' => 'interior',
            'width' => 10,
            'height' => 8,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('handleAiBuildCompleted', ['status' => 'failed']);

        // Areas should still be empty since we didn't refresh
        expect($component->get('areas'))->toBeEmpty();
    });
});

describe('AI Builder dispatches event instead of redirect', function () {
    it('dispatches refreshEstimateFromDb on completed build via Echo', function () {
        config(['ai-build.enabled' => true]);

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create();

        Livewire::actingAs($user)
            ->test('estimates.ai-builder', ['estimate' => $estimate])
            ->call('onAiBuildCompleted', [
                'status' => 'completed',
                'areas_created' => 3,
                'surfaces_created' => 12,
            ])
            ->assertDispatched('refreshEstimateFromDb')
            ->assertSet('aiBuildInProgress', false);
    });

    it('dispatches refreshEstimateFromDb on partial build via polling', function () {
        config(['ai-build.enabled' => true]);

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create();

        // Set up cache to simulate build completion
        Cache::put("ai-build-estimate:{$estimate->id}", [
            'status' => 'partial',
            'message' => 'Built 2 areas',
            'started_at' => now()->toISOString(),
        ]);

        Livewire::actingAs($user)
            ->test('estimates.ai-builder', ['estimate' => $estimate])
            ->call('pollAiBuildStatus')
            ->assertDispatched('refreshEstimateFromDb');
    });
});

describe('Area ID in buildAreaDataFromModel', function () {
    it('includes area database ID in areas array', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Test Room',
            'scope_type' => 'interior',
            'width' => 12,
            'height' => 9,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('refreshEstimateFromDb');

        $areas = $component->get('areas');
        expect($areas[0]['id'])->toBe($area->id);
    });
});

describe('Open AI for area', function () {
    it('opens AI chat with area context', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('ai.estimate_builder');
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->call('openAiForArea', areaIndex: 0, areaName: 'Living Room')
            ->assertSet('showAiChat', true)
            ->assertSet('aiChatInput', 'Help me modify the "Living Room" area. ');
    });
});

describe('AI processing computed property', function () {
    it('returns false when no threads are processing', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        $component = Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate]);

        expect($component->instance()->aiIsProcessing)->toBeFalse();
    });
});

describe('Mobile UI elements', function () {
    it('shows AI button in mobile bottom bar when user has AI permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('ai.estimate_builder');
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->for($deal)->create(['estimator_mode' => 'template']);

        Livewire::actingAs($user)
            ->test('estimates.template-builder', ['estimate' => $estimate])
            ->assertSeeHtml('wire:click="toggleAiChat"');
    });
});
