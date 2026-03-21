<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->deal = Deal::factory()->create();
    $this->estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'created_by' => $this->user->id,
        'status' => 'sent',
    ]);
    $this->area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
    ]);
});

// =========================================================================
// isIncludedInTier — model unit tests
// =========================================================================

it('surface with null package_tiers is included in all tiers', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => null,
        'is_included' => true,
    ]);

    expect($surface->isIncludedInTier('good'))->toBeTrue()
        ->and($surface->isIncludedInTier('better'))->toBeTrue()
        ->and($surface->isIncludedInTier('best'))->toBeTrue();
});

it('surface with package_tiers set is only included in listed tiers', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => ['better', 'best'],
        'is_included' => true,
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse()
        ->and($surface->isIncludedInTier('better'))->toBeTrue()
        ->and($surface->isIncludedInTier('best'))->toBeTrue();
});

it('surface with single tier in package_tiers is excluded from others', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse()
        ->and($surface->isIncludedInTier('better'))->toBeFalse()
        ->and($surface->isIncludedInTier('best'))->toBeTrue();
});

it('forTiers factory state sets package_tiers correctly', function () {
    $surface = EstimateAreaSurface::factory()->forTiers(['good', 'better'])->create([
        'area_id' => $this->area->id,
        'is_included' => true,
    ]);

    expect($surface->package_tiers)->toBe(['good', 'better'])
        ->and($surface->isIncludedInTier('good'))->toBeTrue()
        ->and($surface->isIncludedInTier('better'))->toBeTrue()
        ->and($surface->isIncludedInTier('best'))->toBeFalse();
});

it('persists and reloads package_tiers from database', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => ['good', 'better'],
        'is_included' => true,
    ]);

    $loaded = EstimateAreaSurface::find($surface->id);

    expect($loaded->package_tiers)->toBe(['good', 'better'])
        ->and($loaded->isIncludedInTier('good'))->toBeTrue()
        ->and($loaded->isIncludedInTier('best'))->toBeFalse();
});

// =========================================================================
// excluded_surfaces_display — CompanySetting default
// =========================================================================

it('company setting excluded_surfaces_display defaults to hidden', function () {
    $settings = CompanySetting::getInstance();

    // Ensure it's either null or 'hidden' (not 'shown')
    expect($settings->excluded_surfaces_display ?? 'hidden')->toBe('hidden');
});

it('company setting excluded_surfaces_display can be set to shown', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'shown']);

    $reloaded = CompanySetting::getInstance();
    expect($reloaded->excluded_surfaces_display)->toBe('shown');
});

// =========================================================================
// getExcludedSurfacesDisplay — Estimate cascades to company default
// =========================================================================

it('estimate getExcludedSurfacesDisplay returns hidden when both are unset', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'hidden']);

    $this->estimate->update(['excluded_surfaces_display' => null]);
    $this->estimate->refresh();

    expect($this->estimate->getExcludedSurfacesDisplay())->toBe('hidden');
});

it('estimate getExcludedSurfacesDisplay returns shown when company setting is shown', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'shown']);

    $this->estimate->update(['excluded_surfaces_display' => null]);
    $this->estimate->refresh();

    expect($this->estimate->getExcludedSurfacesDisplay())->toBe('shown');
});

it('estimate level excluded_surfaces_display overrides company default', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'hidden']);

    $this->estimate->update(['excluded_surfaces_display' => 'shown']);
    $this->estimate->refresh();

    expect($this->estimate->getExcludedSurfacesDisplay())->toBe('shown');
});

it('estimate hidden overrides company shown setting', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'shown']);

    $this->estimate->update(['excluded_surfaces_display' => 'hidden']);
    $this->estimate->refresh();

    expect($this->estimate->getExcludedSurfacesDisplay())->toBe('hidden');
});

// =========================================================================
// isIncludedInTier filtering — model-level collection filtering
// =========================================================================

it('filtering surfaces by tier produces correct included and excluded sets', function () {
    $allTierSurface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Walls',
        'package_tiers' => null,
        'is_included' => true,
    ]);

    $bestOnlySurface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Trim',
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    $goodBetterSurface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Ceiling',
        'package_tiers' => ['good', 'better'],
        'is_included' => true,
    ]);

    $allSurfaces = $this->area->surfaces->where('is_included', true);

    // For tier 'good'
    $includedForGood = $allSurfaces->filter(fn ($s) => $s->isIncludedInTier('good'));
    $excludedForGood = $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier('good'));

    expect($includedForGood->pluck('name')->toArray())->toContain('Walls')
        ->and($includedForGood->pluck('name')->toArray())->toContain('Ceiling')
        ->and($excludedForGood->pluck('name')->toArray())->toContain('Trim');

    // For tier 'best'
    $includedForBest = $allSurfaces->filter(fn ($s) => $s->isIncludedInTier('best'));
    $excludedForBest = $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier('best'));

    expect($includedForBest->pluck('name')->toArray())->toContain('Walls')
        ->and($includedForBest->pluck('name')->toArray())->toContain('Trim')
        ->and($excludedForBest->pluck('name')->toArray())->toContain('Ceiling');
});

it('surfaces with no tier restrictions all appear as included regardless of tier', function () {
    $surface1 = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => null,
        'is_included' => true,
    ]);

    $surface2 = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => null,
        'is_included' => true,
    ]);

    $allSurfaces = $this->area->surfaces->where('is_included', true);

    foreach (['good', 'better', 'best'] as $tier) {
        $excluded = $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier($tier));
        expect($excluded)->toBeEmpty();
    }
});

// =========================================================================
// Package tier mapping — getPaintTier on EstimatePackage
// =========================================================================

it('package tier maps to correct paint tier via getPaintTier', function () {
    $basicPackage = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();
    $standardPackage = EstimatePackage::factory()->forEstimate($this->estimate)->standard()->create();
    $premiumPackage = EstimatePackage::factory()->forEstimate($this->estimate)->premium()->create();

    expect($basicPackage->getPaintTier())->toBe('good')
        ->and($standardPackage->getPaintTier())->toBe('better')
        ->and($premiumPackage->getPaintTier())->toBe('best');
});

it('basic package surfaces exclude best-only surfaces via isIncludedInTier', function () {
    EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Walls',
        'package_tiers' => null,
        'is_included' => true,
    ]);

    EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Premium Trim',
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    $basicPackage = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();
    $paintTier = $basicPackage->getPaintTier(); // 'good'

    $allSurfaces = $this->area->surfaces->where('is_included', true);
    $includedForGood = $allSurfaces->filter(fn ($s) => $s->isIncludedInTier($paintTier));
    $excludedForGood = $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier($paintTier));

    expect($includedForGood->pluck('name')->toArray())->toContain('Walls')
        ->and($excludedForGood->pluck('name')->toArray())->toContain('Premium Trim');
});

// =========================================================================
// excluded_surfaces_display logic — hidden vs shown
// =========================================================================

it('excluded surfaces set is empty when display mode is hidden', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'hidden']);

    $this->estimate->update(['excluded_surfaces_display' => null]);
    $this->estimate->refresh();

    EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    $activeTier = 'good';
    $allSurfaces = $this->area->surfaces->where('is_included', true);
    $excludedDisplay = $this->estimate->getExcludedSurfacesDisplay();

    $excludedSurfaces = ($excludedDisplay === 'shown')
        ? $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier($activeTier))
        : collect();

    expect($excludedSurfaces)->toBeEmpty();
});

it('excluded surfaces set is populated when display mode is shown', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'shown']);

    $this->estimate->update(['excluded_surfaces_display' => null]);
    $this->estimate->refresh();

    EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Best Only Trim',
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    $activeTier = 'good';
    $allSurfaces = $this->area->surfaces->where('is_included', true);
    $excludedDisplay = $this->estimate->getExcludedSurfacesDisplay();

    $excludedSurfaces = ($excludedDisplay === 'shown')
        ? $allSurfaces->filter(fn ($s) => ! $s->isIncludedInTier($activeTier))
        : collect();

    expect($excludedSurfaces)->toHaveCount(1)
        ->and($excludedSurfaces->first()->name)->toBe('Best Only Trim');
});

it('when no active tier all surfaces are included and none excluded', function () {
    EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'package_tiers' => ['best'],
        'is_included' => true,
    ]);

    // No active tier (no package selected)
    $activeTier = null;
    $allSurfaces = $this->area->surfaces->where('is_included', true);

    if ($activeTier) {
        $includedSurfaces = $allSurfaces->filter(fn ($s) => $s->isIncludedInTier($activeTier));
        $excludedSurfaces = collect();
    } else {
        $includedSurfaces = $allSurfaces;
        $excludedSurfaces = collect();
    }

    expect($includedSurfaces)->toHaveCount(1)
        ->and($excludedSurfaces)->toBeEmpty();
});
