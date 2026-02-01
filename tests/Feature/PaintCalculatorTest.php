<?php

use App\Livewire\Estimates\PaintCalculator;
use App\Models\CompanySetting;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;
use App\Services\PaintCalculatorService;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
});

describe('CompanySetting Paint Catalog', function () {
    it('has default paint catalog with coverage data', function () {
        $catalog = CompanySetting::getDefaultPaintCatalog();

        expect($catalog)->toBeArray()
            ->and($catalog)->toHaveKey('sherwin_williams')
            ->and($catalog['sherwin_williams']['duration'])->toBeArray()
            ->and($catalog['sherwin_williams']['duration']['price'])->toBe(75.00)
            ->and($catalog['sherwin_williams']['duration']['coverage_sqft'])->toBe(400);
    });

    it('gets paint price from catalog', function () {
        $price = $this->settings->getPaintPrice('sherwin_williams', 'duration');

        expect($price)->toBe(75.00);
    });

    it('gets paint coverage from catalog', function () {
        $coverage = $this->settings->getPaintCoverage('sherwin_williams', 'duration');

        expect($coverage)->toBe(400);
    });

    it('gets paint details from catalog', function () {
        $details = $this->settings->getPaintDetails('sherwin_williams', 'emerald');

        expect($details)->toBeArray()
            ->and($details['price'])->toBe(95.00)
            ->and($details['coverage_sqft'])->toBe(400);
    });

    it('returns null for unknown paint', function () {
        $details = $this->settings->getPaintDetails('unknown_brand', 'unknown_type');

        expect($details)->toBeNull();
    });

    it('normalizes paint brand and type names', function () {
        $details1 = $this->settings->getPaintDetails('Sherwin Williams', 'Duration');
        $details2 = $this->settings->getPaintDetails('sherwin-williams', 'duration');

        expect($details1)->not->toBeNull()
            ->and($details2)->not->toBeNull();
    });

    it('gets available paint brands', function () {
        $brands = $this->settings->getAvailablePaintBrands();

        expect($brands)->toBeArray()
            ->and($brands)->toContain('sherwin_williams')
            ->and($brands)->toContain('benjamin_moore')
            ->and($brands)->toContain('ppg');
    });

    it('gets paint types for brand', function () {
        $types = $this->settings->getPaintTypesForBrand('sherwin_williams');

        expect($types)->toBeArray()
            ->and($types)->toContain('duration')
            ->and($types)->toContain('emerald')
            ->and($types)->toContain('superpaint');
    });
});

describe('CompanySetting Gallon Calculations', function () {
    it('calculates gallons needed with brush/roll method', function () {
        $result = $this->settings->calculateGallonsNeeded(
            sqft: 1000,
            baseCoverageSqft: 400,
            coats: 2,
            applicationMethod: 'brush_roll'
        );

        // Coverage adjusted: 400 * 1.3 = 520 sqft/gal
        // Total sqft: 1000 * 2 = 2000 sqft
        // Gallons: 2000 / 520 = 3.846...
        expect($result)->toBeArray()
            ->and($result['gallons'])->toBeGreaterThan(3.8)
            ->and($result['gallons'])->toBeLessThan(3.9)
            ->and($result['gallons_rounded'])->toBe(4)
            ->and($result['coverage_used'])->toBe(520.0);
    });

    it('calculates gallons needed with spray method', function () {
        $result = $this->settings->calculateGallonsNeeded(
            sqft: 1000,
            baseCoverageSqft: 400,
            coats: 2,
            applicationMethod: 'spray'
        );

        // Coverage adjusted: 400 * 2.0 = 800 sqft/gal
        // Total sqft: 1000 * 2 = 2000 sqft
        // Gallons: 2000 / 800 = 2.5
        expect($result['gallons'])->toBe(2.5)
            ->and($result['gallons_rounded'])->toBe(3) // Rounded up
            ->and($result['coverage_used'])->toBe(800.0);
    });

    it('calculates full paint cost', function () {
        $result = $this->settings->calculatePaintCost(
            sqft: 1000,
            brand: 'sherwin_williams',
            type: 'superpaint', // $55/gal, 350 sqft coverage
            coats: 2,
            applicationMethod: 'brush_roll'
        );

        expect($result)->toBeArray()
            ->and($result)->toHaveKey('gallons')
            ->and($result)->toHaveKey('gallons_rounded')
            ->and($result)->toHaveKey('paint_cost')
            ->and($result)->toHaveKey('recycle_fee')
            ->and($result)->toHaveKey('total_cost')
            ->and($result['paint_cost'])->toBeGreaterThan(0)
            ->and($result['recycle_fee'])->toBeGreaterThan(0);
    });
});

describe('PaintCalculatorService', function () {
    it('can be instantiated', function () {
        $service = new PaintCalculatorService;

        expect($service)->toBeInstanceOf(PaintCalculatorService::class);
    });

    it('calculates gallons needed', function () {
        $service = new PaintCalculatorService;

        $result = $service->calculateGallonsNeeded(
            sqft: 500,
            baseCoverageSqft: 400,
            coats: 2,
            applicationMethod: 'brush_roll'
        );

        expect($result)->toBeArray()
            ->and($result['gallons_rounded'])->toBeGreaterThan(0);
    });

    it('calculates paint cost', function () {
        $service = new PaintCalculatorService;

        $result = $service->calculatePaintCost(
            sqft: 500,
            brand: 'benjamin_moore',
            type: 'aura',
            coats: 2,
            applicationMethod: 'spray'
        );

        expect($result)->toBeArray()
            ->and($result['total_cost'])->toBeGreaterThan(0);
    });

    it('gets all paints as flat list', function () {
        $service = new PaintCalculatorService;

        $paints = $service->getAllPaintsFlat();

        expect($paints)->toBeArray()
            ->and($paints[0])->toHaveKeys(['brand', 'type', 'price', 'coverage_sqft', 'label']);
    });

    it('calculates for estimate area surface', function () {
        $area = EstimateArea::factory()->create();
        $surface = EstimateAreaSurface::factory()
            ->forArea($area)
            ->brushRoll()
            ->sherwinWilliamsDuration()
            ->create(['quantity' => 500, 'coats' => 2]);

        $service = new PaintCalculatorService;
        $result = $service->calculateForSurface($surface);

        expect($result)->toBeArray()
            ->and($result['total_cost'])->toBeGreaterThan(0);
    });

    it('calculates for entire estimate area', function () {
        $area = EstimateArea::factory()->create();

        // Create surfaces directly associated with the area
        EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Wall Painting',
            'surface_type' => 'wall',
            'quantity' => 400,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 300,
            'hourly_labor_rate' => 45,
            'material_cost_per_unit' => 0.15,
            'is_included' => true,
            'application_method' => 'brush_roll',
            'paint_brand' => 'sherwin_williams',
            'paint_type' => 'superpaint',
        ]);

        EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => 'Ceiling Painting',
            'surface_type' => 'ceiling',
            'quantity' => 200,
            'unit' => 'sqft',
            'coats' => 1,
            'production_rate_value' => 400,
            'hourly_labor_rate' => 45,
            'material_cost_per_unit' => 0.12,
            'is_included' => true,
            'application_method' => 'brush_roll',
            'paint_brand' => 'sherwin_williams',
            'paint_type' => 'superpaint',
        ]);

        $service = new PaintCalculatorService;
        $result = $service->calculateForArea($area->fresh());

        expect($result)->toHaveKeys(['surfaces', 'totals'])
            ->and($result['surfaces'])->toHaveCount(2)
            ->and($result['totals']['total_sqft'])->toBe(600.0);
    });
});

describe('EstimateAreaSurface Paint Fields', function () {
    it('has paint configuration fields', function () {
        $surface = EstimateAreaSurface::factory()->create([
            'application_method' => 'spray',
            'paint_brand' => 'benjamin_moore',
            'paint_type' => 'aura',
            'paint_gallons' => 5,
            'paint_cost' => 475.00,
        ]);

        expect($surface->application_method)->toBe('spray')
            ->and($surface->paint_brand)->toBe('benjamin_moore')
            ->and($surface->paint_type)->toBe('aura')
            ->and($surface->paint_gallons)->toBe('5.00')
            ->and($surface->paint_cost)->toBe('475.00');
    });

    it('has application method label attribute', function () {
        $surface = EstimateAreaSurface::factory()->create([
            'application_method' => 'brush_roll',
        ]);

        expect($surface->application_method_label)->toBe('Brush/Roll');

        $surface->application_method = 'spray';
        expect($surface->application_method_label)->toBe('Spray');
    });

    it('has paint brand label attribute', function () {
        $surface = EstimateAreaSurface::factory()->create([
            'paint_brand' => 'sherwin_williams',
        ]);

        expect($surface->paint_brand_label)->toBe('Sherwin Williams');
    });

    it('has paint type label attribute', function () {
        $surface = EstimateAreaSurface::factory()->create([
            'paint_type' => 'exterior_duration',
        ]);

        expect($surface->paint_type_label)->toBe('Exterior Duration');
    });

    it('can calculate and store paint needs', function () {
        $surface = EstimateAreaSurface::factory()->create([
            'quantity' => 500,
            'coats' => 2,
            'application_method' => 'brush_roll',
            'paint_brand' => null,
            'paint_type' => null,
        ]);

        $result = $surface->calculatePaintNeeds('sherwin_williams', 'superpaint');

        expect($result)->toBeArray()
            ->and($surface->fresh()->paint_brand)->toBe('sherwin_williams')
            ->and($surface->fresh()->paint_type)->toBe('superpaint')
            ->and($surface->fresh()->paint_gallons)->not->toBeNull()
            ->and($surface->fresh()->paint_cost)->not->toBeNull();
    });

    it('factory has spray state', function () {
        $surface = EstimateAreaSurface::factory()->spray()->create();

        expect($surface->application_method)->toBe('spray');
    });

    it('factory has brushRoll state', function () {
        $surface = EstimateAreaSurface::factory()->brushRoll()->create();

        expect($surface->application_method)->toBe('brush_roll');
    });

    it('factory has withPaint state', function () {
        $surface = EstimateAreaSurface::factory()
            ->withPaint('ppg', 'diamond')
            ->create();

        expect($surface->paint_brand)->toBe('ppg')
            ->and($surface->paint_type)->toBe('diamond');
    });
});

describe('PaintCalculator Livewire Component', function () {
    it('renders successfully', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->assertStatus(200);
    });

    it('calculates when sqft is entered', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->set('sqft', 500)
            ->set('coats', 2)
            ->set('applicationMethod', 'brush_roll')
            ->set('paintBrand', 'sherwin_williams')
            ->set('paintType', 'superpaint')
            ->assertSet('calculation.gallons_rounded', fn ($value) => $value > 0);
    });

    it('updates calculation when inputs change', function () {
        $user = User::factory()->internal()->create();

        $component = Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->set('sqft', 1000)
            ->set('coats', 2)
            ->set('applicationMethod', 'brush_roll');

        $initialGallons = $component->get('calculation.gallons_rounded');

        $component->set('applicationMethod', 'spray');
        $newGallons = $component->get('calculation.gallons_rounded');

        // Spray should use less gallons due to better coverage
        expect($newGallons)->toBeLessThan($initialGallons);
    });

    it('resets paint type when brand changes', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->set('paintBrand', 'sherwin_williams')
            ->set('paintType', 'duration')
            ->set('paintBrand', 'ppg')
            ->assertSet('paintType', 'diamond'); // First type for PPG
    });

    it('loads surface data when surfaceId provided', function () {
        $user = User::factory()->internal()->create();

        $surface = EstimateAreaSurface::factory()->create([
            'quantity' => 750,
            'coats' => 3,
            'application_method' => 'spray',
            'paint_brand' => 'benjamin_moore',
            'paint_type' => 'aura',
        ]);

        Livewire::actingAs($user)
            ->test(PaintCalculator::class, ['surfaceId' => $surface->id])
            ->assertSet('sqft', 750.0)
            ->assertSet('coats', 3)
            ->assertSet('applicationMethod', 'spray')
            ->assertSet('paintBrand', 'benjamin_moore')
            ->assertSet('paintType', 'aura');
    });

    it('can apply calculation to surface', function () {
        $user = User::factory()->internal()->create();

        $surface = EstimateAreaSurface::factory()->create([
            'quantity' => 500,
            'coats' => 2,
            'application_method' => 'brush_roll',
            'paint_brand' => null,
            'paint_type' => null,
            'paint_gallons' => null,
            'paint_cost' => null,
        ]);

        Livewire::actingAs($user)
            ->test(PaintCalculator::class, ['surfaceId' => $surface->id])
            ->set('paintBrand', 'sherwin_williams')
            ->set('paintType', 'emerald')
            ->call('applyToSurface')
            ->assertDispatched('surface-paint-updated');

        $surface->refresh();

        expect($surface->paint_brand)->toBe('sherwin_williams')
            ->and($surface->paint_type)->toBe('emerald')
            ->and($surface->paint_gallons)->not->toBeNull()
            ->and($surface->paint_cost)->not->toBeNull();
    });

    it('displays paint brands in select', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->assertSee('Sherwin Williams')
            ->assertSee('Benjamin Moore')
            ->assertSee('Ppg');
    });

    it('shows calculation results when sqft provided', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->set('sqft', 1000)
            ->assertSee('Gallons Needed')
            ->assertSee('Paint Cost')
            ->assertSee('Total Material');
    });

    it('shows message when no sqft entered', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(PaintCalculator::class)
            ->set('sqft', 0)
            ->assertSee('Enter square footage to see paint calculation');
    });
});
