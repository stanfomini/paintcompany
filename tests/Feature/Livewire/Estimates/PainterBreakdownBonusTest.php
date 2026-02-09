<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('Painter Breakdown Crew Bonus', function () {
    it('shows crew bonus when crew assigned with bonus percent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);

        // Add labor data so job price is non-zero
        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 16.0,
            'is_included' => true,
        ]);

        $crewUser = User::factory()->crew()->create(['name' => 'Mike Crew Lead']);
        $crewUser->crewProfile->update(['bonus_percent' => 5.0]);
        $job->crews()->attach($crewUser->id, ['status' => 'accepted']);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $crewBonusInfo = $component->get('crewBonusInfo');

        expect($crewBonusInfo)->not->toBeNull();
        expect($crewBonusInfo['crew_name'])->toBe('Mike Crew Lead');
        expect($crewBonusInfo['bonus_percent'])->toBe(5.0);
        expect($crewBonusInfo['bonus_amount'])->toBeGreaterThan(0);
    });

    it('hides bonus when no crew assigned', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $crewBonusInfo = $component->get('crewBonusInfo');

        expect($crewBonusInfo)->toBeNull();
    });

    it('hides bonus when crew has no bonus percent', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);

        $crewUser = User::factory()->crew()->create();
        // bonus_percent defaults to null
        $job->crews()->attach($crewUser->id, ['status' => 'accepted']);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $crewBonusInfo = $component->get('crewBonusInfo');

        expect($crewBonusInfo)->toBeNull();
    });
});
