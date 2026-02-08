<?php

use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageGeneratorService;
    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

it('uses defaultItems pivot instead of items relationship', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
        'warranty_months' => 0,
        'warranty_percent' => 0,
    ]);

    // Create a standalone item and link via pivot
    $item = PackageTemplateItem::factory()->fixed(100, 250)->create([
        'name' => 'Standalone Cleaning',
        'is_included_by_default' => true,
        'service_category_id' => $this->category->id,
    ]);
    $item->defaultPackages()->attach($template->id);

    // Create an item NOT linked via pivot (should NOT be included)
    PackageTemplateItem::factory()->fixed(50, 125)->create([
        'name' => 'Unlinked Item',
        'is_included_by_default' => true,
        'service_category_id' => $this->category->id,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);

    expect($packages)->toHaveCount(1);

    // Check that the standalone item was included
    $package = $packages->first();
    $serviceItems = $package->items()->whereHasMorph('itemable', [\App\Models\EstimateItem::class])->get();
    expect($serviceItems)->toHaveCount(1);

    $estimateItem = $serviceItems->first()->itemable;
    expect($estimateItem->description)->toBe('Standalone Cleaning');
});

it('calculates warranty from warranty item instead of template fields', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'warranty_months' => 6,
        'warranty_percent' => 3.00,
        'service_category_id' => $this->category->id,
    ]);

    // Create warranty item with different percent than template
    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 5.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    // Should use warranty item's 5% (not template's 3%)
    $expectedWarranty = 2000 * (5 / 100);
    expect((float) $package->warranty_price)->toBe($expectedWarranty);
});

it('falls back to template warranty when no warranty items in defaults', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'warranty_months' => 3,
        'warranty_percent' => 1.00,
        'service_category_id' => $this->category->id,
    ]);

    // No warranty items linked via pivot
    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    // Should fall back to template's 1%
    $expectedWarranty = 2000 * (1 / 100);
    expect((float) $package->warranty_price)->toBe($expectedWarranty);
});

it('propagates is_customer_selectable to estimate package items', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'warranty_months' => 0,
        'warranty_percent' => 0,
        'service_category_id' => $this->category->id,
    ]);

    // Customer-selectable item
    $selectableItem = PackageTemplateItem::factory()->fixed(100, 250)->customerSelectable()->create([
        'name' => 'Optional Cleaning',
        'service_category_id' => $this->category->id,
    ]);
    $selectableItem->defaultPackages()->attach($template->id);

    // Non-customer-selectable item
    $nonSelectableItem = PackageTemplateItem::factory()->fixed(50, 125)->create([
        'name' => 'Required Materials',
        'is_customer_selectable' => false,
        'service_category_id' => $this->category->id,
    ]);
    $nonSelectableItem->defaultPackages()->attach($template->id);

    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    $servicePackageItems = $package->items()
        ->whereHasMorph('itemable', [\App\Models\EstimateItem::class])
        ->get();

    expect($servicePackageItems)->toHaveCount(2);

    $selectablePackageItem = $servicePackageItems->first(fn ($pi) => $pi->itemable->description === 'Optional Cleaning');
    $nonSelectablePackageItem = $servicePackageItems->first(fn ($pi) => $pi->itemable->description === 'Required Materials');

    expect($selectablePackageItem->is_customer_selectable)->toBeTrue()
        ->and($nonSelectablePackageItem->is_customer_selectable)->toBeFalse();
});

it('items without default packages are not auto-added', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'warranty_months' => 0,
        'warranty_percent' => 0,
        'service_category_id' => $this->category->id,
    ]);

    // Create standalone item but don't link to any template
    PackageTemplateItem::factory()->fixed(100, 250)->create([
        'name' => 'Orphan Item',
        'service_category_id' => $this->category->id,
    ]);

    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    $serviceItems = $package->items()->whereHasMorph('itemable', [\App\Models\EstimateItem::class])->get();
    expect($serviceItems)->toHaveCount(0);
});

it('warranty items are not duplicated as service items', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'warranty_months' => 6,
        'warranty_percent' => 3.00,
        'service_category_id' => $this->category->id,
    ]);

    // Create warranty item
    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 5.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    // Create regular service item
    $serviceItem = PackageTemplateItem::factory()->cleaningService()->create([
        'service_category_id' => $this->category->id,
    ]);
    $serviceItem->defaultPackages()->attach($template->id);

    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    // Only the cleaning service should appear as a package item (not the warranty)
    $servicePackageItems = $package->items()
        ->whereHasMorph('itemable', [\App\Models\EstimateItem::class])
        ->get();

    expect($servicePackageItems)->toHaveCount(1);
    expect($servicePackageItems->first()->itemable->description)->toBe('Post-Project Cleaning');
});

it('backward compat: uses items() when no defaultItems exist', function () {
    $estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'warranty_months' => 0,
        'warranty_percent' => 0,
        'service_category_id' => $this->category->id,
    ]);

    // Create item linked directly via FK (legacy), no pivot entry
    PackageTemplateItem::factory()->fixed(100, 250)->forPackageTemplate($template)->create([
        'name' => 'Legacy Item',
    ]);

    $packages = $this->service->generateFromTemplates($estimate);
    $package = $packages->first();

    $serviceItems = $package->items()->whereHasMorph('itemable', [\App\Models\EstimateItem::class])->get();
    expect($serviceItems)->toHaveCount(1);
    expect($serviceItems->first()->itemable->description)->toBe('Legacy Item');
});

it('existing tests still pass with forPackageTemplate state', function () {
    $template = PackageTemplate::factory()->good()->create();

    $item = PackageTemplateItem::factory()->forPackageTemplate($template)->cleaningService()->create();

    expect($item->package_template_id)->toBe($template->id)
        ->and($item->packageTemplate->id)->toBe($template->id);
});
