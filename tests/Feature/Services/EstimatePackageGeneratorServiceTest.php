<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageGeneratorService;
});

describe('shouldUseTemplates', function () {
    it('returns false when estimate already has packages', function () {
        $estimate = Estimate::factory()->create();
        EstimatePackage::factory()->forEstimate($estimate)->basic()->create();
        PackageTemplate::factory()->good()->create(['is_active' => true]);

        expect($this->service->shouldUseTemplates($estimate))->toBeFalse();
    });

    it('returns false when no active templates exist', function () {
        $estimate = Estimate::factory()->create();
        PackageTemplate::factory()->good()->create(['is_active' => false]);

        expect($this->service->shouldUseTemplates($estimate))->toBeFalse();
    });

    it('returns true when no packages exist and templates are active', function () {
        $estimate = Estimate::factory()->create();
        PackageTemplate::factory()->good()->create(['is_active' => true]);

        expect($this->service->shouldUseTemplates($estimate))->toBeTrue();
    });
});

describe('generateFromTemplates', function () {
    it('creates estimate packages from all active templates', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 8.5,
        ]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);
        PackageTemplate::factory()->better()->create(['is_active' => true]);
        PackageTemplate::factory()->best()->create(['is_active' => true]);

        $packages = $this->service->generateFromTemplates($estimate);

        expect($packages)->toHaveCount(3)
            ->and($estimate->packages()->count())->toBe(3);
    });

    it('returns empty collection when no templates exist', function () {
        $estimate = Estimate::factory()->create();

        $packages = $this->service->generateFromTemplates($estimate);

        expect($packages)->toBeEmpty();
    });

    it('calculates warranty price correctly', function () {
        // areas_subtotal is RAW cost (COGS) - margins are applied by the generator
        // With 50% interior margin: OTD = 2000 / 0.50 = 4000
        // areas_subtotal already includes margins, so warranty_percent = 5% of 2000 = 100
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        PackageTemplate::factory()->better()->create([
            'is_active' => true,
            'warranty_months' => 6,
            'warranty_percent' => 5,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();
        expect((float) $package->warranty_price)->toBe(100.0);
    });

    it('includes service items from template', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 1000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create(['is_active' => true]);

        PackageTemplateItem::factory()->create([
            'package_template_id' => $template->id,
            'name' => 'Cleaning Service',
            'pricing_type' => 'fixed',
            'price' => 300,
            'is_optional' => false,
            'is_included_by_default' => true,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items')->first();

        // Should have the cleaning service item
        $serviceItems = $package->items->filter(fn ($item) => $item->itemable_type === \App\Models\EstimateItem::class);
        expect($serviceItems)->toHaveCount(1);

        $serviceItem = $serviceItems->first();
        expect($serviceItem->customer_approved)->toBeTrue();
    });

    it('marks optional items as not approved by default', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 1000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create(['is_active' => true]);

        PackageTemplateItem::factory()->create([
            'package_template_id' => $template->id,
            'name' => 'Optional Service',
            'pricing_type' => 'fixed',
            'price' => 200,
            'is_optional' => true,
            'is_included_by_default' => false,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items')->first();
        $optionalItem = $package->items->first();

        expect($optionalItem->customer_approved)->toBeFalse();
    });

    it('sets default package as selected on estimate', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 1000]);

        PackageTemplate::factory()->good()->create(['is_active' => true, 'is_default' => false]);
        PackageTemplate::factory()->better()->create(['is_active' => true, 'is_default' => true]);

        $this->service->generateFromTemplates($estimate);

        $estimate->refresh();
        $selectedPackage = $estimate->selectedPackage;

        expect($selectedPackage)->not->toBeNull()
            ->and($selectedPackage->tier)->toBe(EstimatePackage::TIER_STANDARD);
    });

    it('marks popular template as recommended package', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 1000]);

        PackageTemplate::factory()->better()->create([
            'is_active' => true,
            'is_popular' => true,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();
        expect($package->is_recommended)->toBeTrue();
    });

    it('adds painting surfaces as package items', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 2000]);

        // Create areas with surfaces for the estimate
        $livingRoom = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);
        EstimateAreaSurface::factory()->create(['area_id' => $livingRoom->id, 'name' => 'Walls']);
        EstimateAreaSurface::factory()->create(['area_id' => $livingRoom->id, 'name' => 'Ceiling']);

        $bedroom = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Bedroom',
        ]);
        EstimateAreaSurface::factory()->create(['area_id' => $bedroom->id, 'name' => 'Walls']);

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items')->first();
        $surfaceItems = $package->items->filter(fn ($item) => $item->itemable_type === EstimateAreaSurface::class);

        // Should have 3 surface items: Living Room (2 surfaces) + Bedroom (1 surface)
        expect($surfaceItems)->toHaveCount(3);
        expect($surfaceItems->every(fn ($item) => $item->customer_approved === true))->toBeTrue();
    });

    it('maps template tiers to package tiers correctly', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 1000]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);
        PackageTemplate::factory()->better()->create(['is_active' => true]);
        PackageTemplate::factory()->best()->create(['is_active' => true]);

        $this->service->generateFromTemplates($estimate);

        $packages = $estimate->packages()->orderBy('sort_order')->get();

        expect($packages[0]->tier)->toBe(EstimatePackage::TIER_BASIC)
            ->and($packages[1]->tier)->toBe(EstimatePackage::TIER_STANDARD)
            ->and($packages[2]->tier)->toBe(EstimatePackage::TIER_PREMIUM);
    });

    it('applies tax rate from estimate', function () {
        // areas_subtotal is RAW cost (COGS) - margins are applied by the generator
        // With 50% interior margin: OTD = 1000 / 0.50 = 2000
        // Tax = 10% of 2000 = 200
        // areas_subtotal already includes margins
        // Tax = 10% of 1000 = 100, Total = 1000 + 100 = 1100
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 1000,
            'tax_rate' => 10, // 10% tax
        ]);

        PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        expect((float) $package->tax_rate)->toBe(10.0)
            ->and((float) $package->tax_amount)->toBe(100.0)
            ->and((float) $package->total)->toBe(1100.0);
    });

    it('calculates percentage-based service items correctly', function () {
        // areas_subtotal already includes margins
        // Service = 5% of 2000 = 100
        // Subtotal = 2000 + 100 = 2100
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
        ]);

        PackageTemplateItem::factory()->create([
            'package_template_id' => $template->id,
            'name' => 'Premium Service',
            'pricing_type' => 'percent',
            'percent' => 5,
            'is_included_by_default' => true,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        expect((float) $package->subtotal)->toBe(2100.0);
    });
});

describe('clearGeneratedPackages', function () {
    it('removes all packages and their items', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 1000]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);
        PackageTemplate::factory()->better()->create(['is_active' => true]);

        $this->service->generateFromTemplates($estimate);

        expect($estimate->packages()->count())->toBe(2);

        $this->service->clearGeneratedPackages($estimate);

        expect($estimate->packages()->count())->toBe(0)
            ->and($estimate->fresh()->selected_package_id)->toBeNull();
    });
});

describe('regenerateFromTemplates', function () {
    it('clears existing and creates new packages', function () {
        $estimate = Estimate::factory()->create(['areas_subtotal' => 1000]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        // Generate initial packages
        $this->service->generateFromTemplates($estimate);
        $initialPackageId = $estimate->packages()->first()->id;

        // Add another template
        PackageTemplate::factory()->better()->create(['is_active' => true]);

        // Regenerate
        $this->service->regenerateFromTemplates($estimate);

        expect($estimate->packages()->count())->toBe(2)
            ->and($estimate->packages()->where('id', $initialPackageId)->exists())->toBeFalse();
    });
});

describe('estimate integration', function () {
    it('generates packages when estimate is marked as sent', function () {
        $estimate = Estimate::factory()->create([
            'status' => 'draft',
            'areas_subtotal' => 2500,
        ]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);
        PackageTemplate::factory()->better()->create(['is_active' => true, 'is_default' => true]);

        $estimate->markAsSent();

        expect($estimate->packages()->count())->toBe(2)
            ->and($estimate->status)->toBe('sent')
            ->and($estimate->selected_package_id)->not->toBeNull();
    });

    it('does not generate packages if legacy packages already exist', function () {
        $estimate = Estimate::factory()->create([
            'status' => 'draft',
            'areas_subtotal' => 2500,
        ]);

        // Create legacy package
        $legacyPackage = EstimatePackage::factory()->forEstimate($estimate)->basic()->create([
            'name' => 'Legacy Package',
        ]);

        PackageTemplate::factory()->good()->create(['is_active' => true]);

        $estimate->markAsSent();

        // Should still only have the legacy package
        expect($estimate->packages()->count())->toBe(1)
            ->and($estimate->packages()->first()->name)->toBe('Legacy Package');
    });

    it('public proposal uses generated packages after sending', function () {
        $estimate = Estimate::factory()->create([
            'status' => 'draft',
            'areas_subtotal' => 2000,
        ]);

        PackageTemplate::factory()->better()->create([
            'is_active' => true,
            'warranty_percent' => 3,
            'is_default' => true,
        ]);

        $estimate->markAsSent();

        // Now ProposalPackages should use legacy system (packages exist)
        $hasPackages = $estimate->packages()->exists();
        $hasTemplates = PackageTemplate::active()->exists();

        // usePackageTemplates = hasTemplates && !hasPackages
        $usePackageTemplates = $hasTemplates && ! $hasPackages;

        expect($usePackageTemplates)->toBeFalse()
            ->and($hasPackages)->toBeTrue();
    });
});
