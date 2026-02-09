<?php

use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
        'valid_until' => now()->addDays(30),
    ]);

    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );

    // Create package templates
    $this->goodTemplate = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);
    $this->betterTemplate = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
    ]);

    // Create a customer-selectable warranty item
    $this->warrantyItem = PackageTemplateItem::factory()->warranty(12, 5.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $this->warrantyItem->defaultPackages()->attach([$this->betterTemplate->id]);

    // Create a customer-selectable service item
    $this->selectableItem = PackageTemplateItem::factory()->cleaningService()->customerSelectable()->create([
        'service_category_id' => $this->category->id,
    ]);
    $this->selectableItem->defaultPackages()->attach([$this->betterTemplate->id]);

    // Create a non-customer-selectable item (estimator-decided)
    $this->nonSelectableItem = PackageTemplateItem::factory()->fixed(50, 125)->create([
        'name' => 'Premium Materials Upgrade',
        'is_customer_selectable' => false,
        'is_optional' => false,
        'is_included_by_default' => true,
        'service_category_id' => $this->category->id,
    ]);
    $this->nonSelectableItem->defaultPackages()->attach([$this->betterTemplate->id]);
});

it('loads package templates with customer selectability info', function () {
    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->assertSet('usePackageTemplates', true)
        ->assertSet('selectedTier', 'better');
});

it('customer can toggle customer-selectable items', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $itemKey = 'item_'.$this->selectableItem->id;

    // Should start as included by default
    $component->assertSet("selectedPackageItems.{$itemKey}", true);

    // Toggle off
    $component->call('togglePackageItem', $itemKey)
        ->assertSet("selectedPackageItems.{$itemKey}", false);

    // Toggle back on
    $component->call('togglePackageItem', $itemKey)
        ->assertSet("selectedPackageItems.{$itemKey}", true);
});

it('customer cannot toggle non-customer-selectable items', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $itemKey = 'item_'.$this->nonSelectableItem->id;

    // Should start as included by default
    $component->assertSet("selectedPackageItems.{$itemKey}", true);

    // Try to toggle - should stay true (toggle ignored)
    $component->call('togglePackageItem', $itemKey)
        ->assertSet("selectedPackageItems.{$itemKey}", true);
});

it('warranty items are included in items list with is_warranty flag', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $templates = $component->get('packageTemplates');
    $betterTemplate = collect($templates)->firstWhere('tier', 'better');

    $items = collect($betterTemplate['items']);

    // Warranty item should be in the items list with is_warranty=true
    $warrantyItems = $items->where('is_warranty', true);
    expect($warrantyItems)->toHaveCount(1)
        ->and($warrantyItems->first()['name'])->toBe('1-Year Warranty');

    // Service items should have is_warranty=false
    $serviceItems = $items->where('is_warranty', false);
    $serviceNames = $serviceItems->pluck('name')->toArray();
    expect($serviceNames)->toContain('Post-Project Cleaning')
        ->and($serviceNames)->toContain('Premium Materials Upgrade');
});

it('customer can toggle warranty items', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $warrantyKey = 'item_'.$this->warrantyItem->id;

    // Should start as included by default
    $component->assertSet("selectedPackageItems.{$warrantyKey}", true);

    // Toggle off warranty
    $component->call('togglePackageItem', $warrantyKey)
        ->assertSet("selectedPackageItems.{$warrantyKey}", false);

    // Toggle back on
    $component->call('togglePackageItem', $warrantyKey)
        ->assertSet("selectedPackageItems.{$warrantyKey}", true);
});

it('recalculates total when customer toggles selectable item', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $itemKey = 'item_'.$this->selectableItem->id;

    // Get total with item included
    $totalWithItem = $component->get('selectedTemplateTotal');

    // Toggle off the selectable item
    $component->call('togglePackageItem', $itemKey);

    // Total should decrease
    $totalWithout = $component->get('selectedTemplateTotal');
    expect($totalWithout)->toBeLessThan($totalWithItem);
});

it('recalculates total when customer toggles warranty item', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $warrantyKey = 'item_'.$this->warrantyItem->id;

    // Get total with warranty included
    $totalWithWarranty = $component->get('selectedTemplateTotal');

    // Toggle off warranty
    $component->call('togglePackageItem', $warrantyKey);

    // Total should decrease by warranty amount (5% of 2000 = 100)
    $totalWithout = $component->get('selectedTemplateTotal');
    expect($totalWithout)->toBeLessThan($totalWithWarranty);
    expect($totalWithWarranty - $totalWithout)->toBe(100.0);
});

it('shows customer-selectable items with toggle in template', function () {
    $itemKey = 'item_'.$this->selectableItem->id;

    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->assertSee('Post-Project Cleaning')
        // Toggle off to see "Click to add" text
        ->call('togglePackageItem', $itemKey)
        ->assertSee('Click to add');
});

it('shows non-selectable items as included without toggle', function () {
    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->assertSee('Premium Materials Upgrade')
        ->assertSee('Included in package');
});

it('good template has no warranty items when none linked', function () {
    // Good template has no warranty item in defaults
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $templates = $component->get('packageTemplates');
    $goodTemplate = collect($templates)->firstWhere('tier', 'good');

    // No warranty items should be in the items list for good template
    $warrantyItems = collect($goodTemplate['items'])->where('is_warranty', true);
    expect($warrantyItems)->toHaveCount(0);
});

it('selects tier directly without warranty picker', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    // selectTier should work directly without needing warranty picker
    $component->call('selectTier', 'good')
        ->assertSet('selectedTier', 'good')
        ->assertDispatched('tier-selected');
});

it('proceeds to signature with selected items', function () {
    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->call('proceedToSignature')
        ->assertDispatched('proceed-to-signature');
});

it('separates warranty and service totals in computed properties', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better');

    $warrantyTotal = $component->get('selectedTemplateWarrantyTotal');
    $servicesTotal = $component->get('selectedTemplateServicesTotal');

    // Warranty: 5% of 2000 = 100
    expect($warrantyTotal)->toBe(100.0);

    // Services: cleaning ($400) + materials upgrade ($125) = $525
    expect($servicesTotal)->toBe(525.0);
});
