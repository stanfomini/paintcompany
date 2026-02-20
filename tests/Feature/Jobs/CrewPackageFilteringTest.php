<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\Job;
use App\Models\User;
use App\Services\JobDispatchService;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a crew user, dispatch the given job to them, accept it, and return the crew user.
 */
function createAcceptedCrew(Job $job): User
{
    $crew = User::factory()->crew()->create();
    $service = app(JobDispatchService::class);
    $service->dispatchToCrews($job, [$crew->id]);
    $service->acceptJob($job, $crew);

    return $crew;
}

/**
 * Create a crew user, dispatch the given job to them (without accepting), and return the crew user.
 */
function createDispatchedCrew(Job $job): User
{
    $crew = User::factory()->crew()->create();
    app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

    return $crew;
}

/**
 * Build a full estimate scaffold: estimate with 2 areas, each having a wall surface,
 * a selected package that includes only the first area's surface, and a job.
 *
 * @return array{estimate: Estimate, area1: EstimateArea, area2: EstimateArea, surface1: EstimateAreaSurface, surface2: EstimateAreaSurface, package: EstimatePackage, job: Job}
 */
function buildPackageScenario(): array
{
    $estimate = Estimate::factory()->create(['project_type' => 'interior']);

    // Area 1 -- included in the package
    $area1 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room']);
    $surface1 = EstimateAreaSurface::factory()->wall()->forArea($area1)->create([
        'labor_hours' => 5.0,
        'labor_cost' => 250.0,
        'material_cost' => 50.0,
        'total' => 300.0,
        'quantity' => 400,
        'coats' => 2,
        'is_included' => true,
    ]);

    // Area 2 -- excluded from the package
    $area2 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Kitchen']);
    $surface2 = EstimateAreaSurface::factory()->wall()->forArea($area2)->create([
        'labor_hours' => 8.0,
        'labor_cost' => 400.0,
        'material_cost' => 80.0,
        'total' => 480.0,
        'quantity' => 600,
        'coats' => 2,
        'is_included' => true,
    ]);

    // Package: selected, includes only surface1
    $package = EstimatePackage::factory()
        ->selected()
        ->forEstimate($estimate)
        ->create();

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateAreaSurface($surface1)
        ->approved()
        ->create();

    $job = Job::factory()->create([
        'estimate_id' => $estimate->id,
        'deal_id' => $estimate->deal_id,
    ]);

    return compact('estimate', 'area1', 'area2', 'surface1', 'surface2', 'package', 'job');
}

// ---------------------------------------------------------------------------
// 1. Package auto-detection
// ---------------------------------------------------------------------------

describe('Package Auto-Detection', function () {
    it('auto-detects the selected package on mount', function () {
        $scenario = buildPackageScenario();
        $crew = createDispatchedCrew($scenario['job']);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $scenario['job']])
            ->assertSet('viewingPackageId', $scenario['package']->id);
    });

    it('falls back to recommended package when none is selected', function () {
        $estimate = Estimate::factory()->create();
        $recommendedPackage = EstimatePackage::factory()
            ->recommended()
            ->forEstimate($estimate)
            ->create(['sort_order' => 2]);

        // Create another non-recommended package with lower sort order
        EstimatePackage::factory()
            ->forEstimate($estimate)
            ->create(['sort_order' => 1, 'is_recommended' => false, 'is_selected' => false]);

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSet('viewingPackageId', $recommendedPackage->id);
    });

    it('falls back to first package by sort order when none is selected or recommended', function () {
        $estimate = Estimate::factory()->create();

        EstimatePackage::factory()
            ->forEstimate($estimate)
            ->create(['sort_order' => 2, 'is_recommended' => false, 'is_selected' => false]);

        $firstPackage = EstimatePackage::factory()
            ->forEstimate($estimate)
            ->create(['sort_order' => 1, 'is_recommended' => false, 'is_selected' => false]);

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSet('viewingPackageId', $firstPackage->id);
    });

    it('sets viewingPackageId to null when estimate has no packages', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSet('viewingPackageId', null);
    });
});

// ---------------------------------------------------------------------------
// 2. Data filtering by package
// ---------------------------------------------------------------------------

describe('Data Filtering by Package', function () {
    it('renders area breakdowns showing only the package-included area', function () {
        $scenario = buildPackageScenario();
        $crew = createAcceptedCrew($scenario['job']);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $scenario['job']]);

        // Package includes only surface1 (Living Room); Kitchen should be skipped
        $component->assertSet('viewingPackageId', $scenario['package']->id);
        $component->assertSee('Living Room');
        $component->assertDontSee('Kitchen');
    });

    it('excludes non-approved package items from area breakdowns', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Hallway']);
        $surface1 = EstimateAreaSurface::factory()->wall()->forArea($area1)->create([
            'labor_hours' => 3.0,
            'is_included' => true,
        ]);

        $area2 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Bedroom']);
        $surface2 = EstimateAreaSurface::factory()->wall()->forArea($area2)->create([
            'labor_hours' => 6.0,
            'is_included' => true,
        ]);

        $package = EstimatePackage::factory()
            ->selected()
            ->forEstimate($estimate)
            ->create();

        // surface1 is approved
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateAreaSurface($surface1)
            ->approved()
            ->create();

        // surface2 is NOT approved (customer declined)
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateAreaSurface($surface2)
            ->notApproved()
            ->create();

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createAcceptedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job]);

        // Only Hallway surface is approved in the package
        $component->assertSee('Hallway');
        $component->assertDontSee('Bedroom');
    });

    it('excludes non-included package items from area breakdowns', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Office']);
        $surface1 = EstimateAreaSurface::factory()->wall()->forArea($area1)->create([
            'labor_hours' => 4.0,
            'is_included' => true,
        ]);

        $area2 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Garage']);
        $surface2 = EstimateAreaSurface::factory()->wall()->forArea($area2)->create([
            'labor_hours' => 10.0,
            'is_included' => true,
        ]);

        $package = EstimatePackage::factory()
            ->selected()
            ->forEstimate($estimate)
            ->create();

        // surface1 is included
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateAreaSurface($surface1)
            ->approved()
            ->create();

        // surface2 is excluded from package
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateAreaSurface($surface2)
            ->excluded()
            ->create();

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createAcceptedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job]);

        $component->assertSee('Office');
        $component->assertDontSee('Garage');
    });

    it('shows all areas when no package is present', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Den']);
        EstimateAreaSurface::factory()->wall()->forArea($area1)->create([
            'labor_hours' => 4.0,
            'is_included' => true,
        ]);

        $area2 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Sunroom']);
        EstimateAreaSurface::factory()->wall()->forArea($area2)->create([
            'labor_hours' => 6.0,
            'is_included' => true,
        ]);

        // No packages at all
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createAcceptedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job]);

        // Both areas should appear because there is no package filter
        $component->assertSee('Den');
        $component->assertSee('Sunroom');
    });

    it('filters materialsNeeded to only include surfaces from the selected package', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Master Bedroom']);
        $surface1 = EstimateAreaSurface::factory()->wall()->forArea($area1)->create([
            'labor_hours' => 5.0,
            'is_included' => true,
            'paint_brand' => 'sherwin_williams',
            'paint_type' => 'duration',
        ]);

        $area2 = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Guest Room']);
        $surface2 = EstimateAreaSurface::factory()->wall()->forArea($area2)->create([
            'labor_hours' => 7.0,
            'is_included' => true,
            'paint_brand' => 'benjamin_moore',
            'paint_type' => 'aura',
        ]);

        $package = EstimatePackage::factory()
            ->selected()
            ->forEstimate($estimate)
            ->create();

        // Only include surface1 in the package
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateAreaSurface($surface1)
            ->approved()
            ->create();

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createAcceptedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job]);

        // The component should render with only the package-filtered materials
        $component->assertSet('viewingPackageId', $package->id);
        $component->assertSee('Master Bedroom');
        $component->assertDontSee('Guest Room');
    });
});

// ---------------------------------------------------------------------------
// 3. Crew Job-Breakdown Component
// ---------------------------------------------------------------------------

describe('Crew Job-Breakdown Component', function () {
    it('renders the crew job-breakdown page successfully', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertStatus(200);
    });

    it('embeds the jobs.painter-breakdown child component', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Dining Room']);
        EstimateAreaSurface::factory()->wall()->forArea($area)->create([
            'labor_hours' => 4.0,
            'is_included' => true,
        ]);

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job]);

        $html = $component->html();

        // The crew.job-breakdown template contains @livewire('jobs.painter-breakdown', ...)
        // which embeds the painter-breakdown as a child Livewire component.
        // Child components are rendered with wire:name attribute.
        expect($html)->toContain('jobs.painter-breakdown');
    });

    it('denies access to crew not dispatched to the job', function () {
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = User::factory()->crew()->create();

        // Crew is NOT dispatched to this job
        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertNotFound();
    });

    it('denies access when job has no estimate', function () {
        $job = Job::factory()->create(['estimate_id' => null]);
        $crew = createDispatchedCrew($job);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertNotFound();
    });

    it('does not render Good Better Best tier selector', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create();
        EstimateAreaSurface::factory()->wall()->forArea($area)->create([
            'labor_hours' => 3.0,
            'is_included' => true,
        ]);

        $job = Job::factory()->create([
            'estimate_id' => $estimate->id,
            'deal_id' => $estimate->deal_id,
        ]);
        $crew = createDispatchedCrew($job);

        $component = Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job]);

        $html = $component->html();

        // The crew breakdown should NOT show Good/Better/Best tier selector
        // because it uses the job painter breakdown, not the estimate version
        expect($html)->not->toContain('Good / Better / Best');
    });
});
