<?php

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageGeneratorService;
});

describe('defaultItems pivot relationship', function () {
    it('uses defaultItems when available instead of legacy items', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Create a legacy item (direct FK) - should be ignored when defaults exist
        PackageTemplateItem::factory()->create([
            'package_template_id' => $template->id,
            'name' => 'Legacy Service',
            'pricing_type' => 'fixed',
            'price' => 100,
            'is_included_by_default' => true,
        ]);

        // Create standalone items linked via pivot
        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Default Service A',
                'pricing_type' => 'fixed',
                'price' => 200,
                'is_included_by_default' => true,
            ]);

        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Default Service B',
                'pricing_type' => 'fixed',
                'price' => 300,
                'is_included_by_default' => true,
            ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items.itemable')->first();
        $serviceItems = $package->items
            ->filter(fn ($item) => $item->itemable_type === EstimateItem::class);

        // Should have 2 items from defaults, not the 1 legacy item
        expect($serviceItems)->toHaveCount(2);

        $names = $serviceItems->map(fn ($item) => $item->itemable->description)->sort()->values()->toArray();
        expect($names)->toBe(['Default Service A', 'Default Service B']);
    });

    it('falls back to legacy items when no defaultItems exist', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Create legacy item (direct FK) only - no pivot entries
        PackageTemplateItem::factory()->create([
            'package_template_id' => $template->id,
            'name' => 'Legacy Service',
            'pricing_type' => 'fixed',
            'price' => 100,
            'is_included_by_default' => true,
        ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items.itemable')->first();
        $serviceItems = $package->items
            ->filter(fn ($item) => $item->itemable_type === EstimateItem::class);

        expect($serviceItems)->toHaveCount(1);
        expect($serviceItems->first()->itemable->description)->toBe('Legacy Service');
    });

    it('calculates subtotal correctly using defaultItems', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Cleaning',
                'pricing_type' => 'fixed',
                'price' => 500,
                'is_included_by_default' => true,
            ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        // Subtotal = painting (2000) + cleaning (500) = 2500
        expect((float) $package->subtotal)->toBe(2500.0);
    });
});

describe('warranty item handling', function () {
    it('detects warranty from customer-selectable percent item in defaults', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Create warranty item via pivot
        PackageTemplateItem::factory()
            ->warranty(months: 24, percent: 5.0)
            ->defaultingTo($template)
            ->create();

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        // Warranty = 5% of 2000 = 100
        expect((float) $package->warranty_price)->toBe(100.0);
        expect((int) $package->warranty_years)->toBe(2);
        expect($package->warranty_type)->toBe('2-Year Warranty');
    });

    it('excludes warranty items from service items list', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Warranty item (should NOT appear as service item)
        PackageTemplateItem::factory()
            ->warranty(months: 12, percent: 3.0)
            ->defaultingTo($template)
            ->create();

        // Normal service item (should appear)
        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Cleaning Service',
                'pricing_type' => 'fixed',
                'price' => 400,
                'is_included_by_default' => true,
                'is_customer_selectable' => false,
            ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items.itemable')->first();
        $serviceItems = $package->items
            ->filter(fn ($item) => $item->itemable_type === EstimateItem::class);

        // Only the cleaning service should appear, not the warranty
        expect($serviceItems)->toHaveCount(1);
        expect($serviceItems->first()->itemable->description)->toBe('Cleaning Service');
    });

    it('excludes warranty items from services subtotal calculation', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Warranty = 5% of 2000 = 100 (handled separately as warranty_price)
        PackageTemplateItem::factory()
            ->warranty(months: 12, percent: 5.0)
            ->defaultingTo($template)
            ->create();

        // Service = fixed $300
        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Paint Touch-up Kit',
                'pricing_type' => 'fixed',
                'price' => 300,
                'is_included_by_default' => true,
                'is_customer_selectable' => false,
            ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        // Subtotal = painting (2000) + service (300) = 2300 (warranty NOT included in subtotal)
        expect((float) $package->subtotal)->toBe(2300.0);
        // Warranty = 100, shown separately
        expect((float) $package->warranty_price)->toBe(100.0);
        // Total = subtotal (2300) + warranty (100) = 2400
        expect((float) $package->total)->toBe(2400.0);
    });

    it('falls back to template warranty when no warranty item in defaults', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 5,
            'warranty_months' => 12,
        ]);

        // No items at all (neither legacy nor pivot)
        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        // Should use template's warranty_percent: 5% of 2000 = 100
        expect((float) $package->warranty_price)->toBe(100.0);
        expect((int) $package->warranty_years)->toBe(1);
    });

    it('derives warranty years from month-based warranty name', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        PackageTemplateItem::factory()
            ->warranty(months: 6, percent: 2.0)
            ->defaultingTo($template)
            ->create();

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->first();

        // 6 months => ceil(6/12) = 1 year
        expect((int) $package->warranty_years)->toBe(1);
        expect($package->warranty_type)->toBe('6-Month Warranty');
    });
});

describe('is_customer_selectable propagation', function () {
    it('sets is_customer_selectable on estimate package items from template items', function () {
        $estimate = Estimate::factory()->create([
            'areas_subtotal' => 2000,
            'tax_rate' => 0,
        ]);

        $template = PackageTemplate::factory()->good()->create([
            'is_active' => true,
            'warranty_percent' => 0,
            'warranty_months' => 0,
        ]);

        // Customer-selectable service
        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Extended Cleaning',
                'pricing_type' => 'fixed',
                'price' => 500,
                'is_included_by_default' => true,
                'is_customer_selectable' => true,
                'is_optional' => true,
            ]);

        // Non-selectable service
        PackageTemplateItem::factory()
            ->defaultingTo($template)
            ->create([
                'name' => 'Base Cleanup',
                'pricing_type' => 'fixed',
                'price' => 100,
                'is_included_by_default' => true,
                'is_customer_selectable' => false,
                'is_optional' => false,
            ]);

        $this->service->generateFromTemplates($estimate);

        $package = $estimate->packages()->with('items.itemable')->first();
        $serviceItems = $package->items
            ->filter(fn ($item) => $item->itemable_type === EstimateItem::class);

        $selectable = $serviceItems->firstWhere(fn ($item) => $item->itemable->description === 'Extended Cleaning');
        $notSelectable = $serviceItems->firstWhere(fn ($item) => $item->itemable->description === 'Base Cleanup');

        expect($selectable->is_customer_selectable)->toBeTrue();
        expect($notSelectable->is_customer_selectable)->toBeFalse();
    });
});
