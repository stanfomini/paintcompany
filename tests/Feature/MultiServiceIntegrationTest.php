<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimatePackage;
use App\Models\PackageTemplate;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\EstimateCostBreakdownService;
use App\Services\EstimatePackageGeneratorService;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();

    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);

    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
    $this->cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
});

describe('Per-Service Cost Breakdown', function () {
    it('cost breakdown service returns per-service data', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior', 'areas_subtotal' => 5000]);

        // Create painting package
        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(5000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        // Create cleaning package
        EstimatePackage::factory()->forEstimate($estimate)->create([
            'name' => 'Cleaning Package',
            'service_category_id' => $this->cleaning->id,
            'subtotal' => 800,
            'total' => 800,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->getPerServiceBreakdown($estimate);

        expect($breakdown)->toHaveKey('painting');
        expect($breakdown)->toHaveKey('cleaning');
        expect($breakdown['painting']['price'])->toBe(5000.0);
        expect($breakdown['cleaning']['price'])->toBe(800.0);
    });

    it('per-service breakdown shows correct category metadata', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior', 'areas_subtotal' => 5000]);

        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(5000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->getPerServiceBreakdown($estimate);

        expect($breakdown)->toHaveKey('painting');
        expect($breakdown['painting']['category_name'])->toBe('Painting');
        expect($breakdown['painting']['category_color'])->toBe('blue');
    });

    it('defaults to painting when no service category set', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(3000)->create([
                'service_category_id' => null,
            ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->getPerServiceBreakdown($estimate);

        expect($breakdown)->toHaveKey('painting');
        expect($breakdown['painting']['category_name'])->toBe('Painting');
        expect($breakdown['painting']['price'])->toBe(3000.0);
    });

    it('returns empty array when no packages exist', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->getPerServiceBreakdown($estimate);

        expect($breakdown)->toBeEmpty();
    });

    it('aggregates multiple packages under same service category', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Two painting packages (Good and Better)
        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(2000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        EstimatePackage::factory()->forEstimate($estimate)->standard()
            ->withPricing(3000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->getPerServiceBreakdown($estimate);

        expect($breakdown)->toHaveKey('painting');
        expect($breakdown['painting']['price'])->toBe(5000.0);
        expect($breakdown['painting']['packages'])->toHaveCount(2);
    });

    it('cost breakdown page shows per-service section when multiple services', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior', 'areas_subtotal' => 5000]);

        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(5000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        EstimatePackage::factory()->forEstimate($estimate)->create([
            'name' => 'Cleaning Package',
            'service_category_id' => $this->cleaning->id,
            'subtotal' => 800,
            'total' => 800,
            'tax_rate' => 0,
            'tax_amount' => 0,
        ]);

        Livewire::test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSee('Breakdown by Service')
            ->assertSee('Painting')
            ->assertSee('Cleaning');
    });

    it('cost breakdown page hides per-service section for single service', function () {
        $estimate = Estimate::factory()->create(['project_type' => 'interior', 'areas_subtotal' => 5000]);

        EstimatePackage::factory()->forEstimate($estimate)->basic()
            ->withPricing(5000)->create([
                'service_category_id' => $this->painting->id,
            ]);

        Livewire::test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertDontSee('Breakdown by Service');
    });
});

describe('Package Generation with Service Categories', function () {
    it('generates packages filtered by service category', function () {
        PackageTemplate::factory()->good()->forService($this->painting)->create();
        PackageTemplate::factory()->better()->forService($this->painting)->create();
        PackageTemplate::factory()->good()->forService($this->cleaning)->create();

        $estimate = Estimate::factory()->create(['areas_subtotal' => 5000]);

        // Add an area so the generator has surfaces to work with
        EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        $service = new EstimatePackageGeneratorService;

        $paintingPackages = $service->generateFromTemplates($estimate, $this->painting);
        expect($paintingPackages)->toHaveCount(2);

        foreach ($paintingPackages as $pkg) {
            expect($pkg->service_category_id)->toBe($this->painting->id);
        }
    });

    it('generates all packages when no service category filter', function () {
        PackageTemplate::factory()->good()->forService($this->painting)->create();
        PackageTemplate::factory()->good()->forService($this->cleaning)->create();

        $estimate = Estimate::factory()->create(['areas_subtotal' => 5000]);

        EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        $service = new EstimatePackageGeneratorService;

        $allPackages = $service->generateFromTemplates($estimate);
        expect($allPackages)->toHaveCount(2);
    });
});
