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

it('warranty items are separated from service items in template data', function () {
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $templates = $component->get('packageTemplates');
    $betterTemplate = collect($templates)->firstWhere('tier', 'better');

    // Warranty should be reflected in warranty fields, not in items list
    expect($betterTemplate['warranty_amount'])->toBeGreaterThan(0);

    // Service items should NOT include the warranty item
    $itemNames = collect($betterTemplate['items'])->pluck('name')->toArray();
    expect($itemNames)->not->toContain('1-Year Warranty');

    // But should include the selectable and non-selectable items
    expect($itemNames)->toContain('Post-Project Cleaning')
        ->and($itemNames)->toContain('Premium Materials Upgrade');
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

it('shows customer-selectable items with toggle in template', function () {
    $itemKey = 'item_'.$this->selectableItem->id;

    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->call('selectWarranty', 'better')
        ->call('confirmSelection')
        ->assertSee('Post-Project Cleaning')
        // Toggle off to see "Click to add" text
        ->call('togglePackageItem', $itemKey)
        ->assertSee('Click to add');
});

it('shows non-selectable items as included without toggle', function () {
    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->call('selectWarranty', 'better')
        ->call('confirmSelection')
        ->assertSee('Premium Materials Upgrade')
        ->assertSee('Included in package');
});

it('falls back to template warranty when no warranty items in defaults', function () {
    // Good template has no warranty item in defaults
    $component = Livewire::test('public.proposal-packages', ['estimate' => $this->estimate]);

    $templates = $component->get('packageTemplates');
    $goodTemplate = collect($templates)->firstWhere('tier', 'good');

    // Should fall back to template's warranty_percent (1%)
    $expectedWarranty = 2000 * (1 / 100);
    expect($goodTemplate['warranty_amount'])->toBe($expectedWarranty);
});

it('proceeds to signature with warranty selection', function () {
    Livewire::test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectTier', 'better')
        ->call('selectWarranty', 'better')
        ->call('confirmSelection')
        ->call('proceedToSignature')
        ->assertDispatched('proceed-to-signature');
});
