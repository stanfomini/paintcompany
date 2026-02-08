<?php

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\User;
use App\Services\EstimatePackageGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);

    $this->estimate = Estimate::factory()->create([
        'areas_subtotal' => 2000,
        'tax_rate' => 0,
    ]);

    // Create a package template with items
    $this->template = PackageTemplate::factory()->good()->create(['is_active' => true]);

    $this->optionalItem = PackageTemplateItem::factory()->create([
        'package_template_id' => $this->template->id,
        'name' => 'Post-Project Cleaning',
        'description' => 'Professional cleaning after completion.',
        'pricing_type' => 'fixed',
        'cost' => 200,
        'price' => 400,
        'is_optional' => true,
        'is_included_by_default' => false,
    ]);

    $this->requiredItem = PackageTemplateItem::factory()->create([
        'package_template_id' => $this->template->id,
        'name' => 'Touch-up Kit',
        'pricing_type' => 'fixed',
        'cost' => 20,
        'price' => 50,
        'is_optional' => false,
        'is_included_by_default' => true,
    ]);

    // Generate packages
    $generator = new EstimatePackageGeneratorService;
    $generator->generateFromTemplates($this->estimate);
});

it('can open addon modal and see available optional services', function () {
    $package = $this->estimate->packages()->first();

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('openAddonModal', $package->id)
        ->assertSet('showAddonModal', true)
        ->assertSet('addonPackageId', $package->id);
});

it('adds a service addon to a package', function () {
    $package = $this->estimate->packages()->first();

    $serviceCountBefore = $package->items()
        ->where('itemable_type', EstimateItem::class)
        ->count();

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('openAddonModal', $package->id)
        ->call('addServiceAddon', $this->optionalItem->id);

    $serviceCountAfter = $package->items()
        ->where('itemable_type', EstimateItem::class)
        ->count();

    expect($serviceCountAfter)->toBe($serviceCountBefore + 1);

    // Verify the EstimateItem was created with correct data
    $estimateItem = EstimateItem::where('estimate_id', $this->estimate->id)
        ->where('description', 'Post-Project Cleaning')
        ->latest()
        ->first();

    expect($estimateItem)->not->toBeNull();
    expect((float) $estimateItem->total)->toBe(400.0);
    expect((float) $estimateItem->getRawOriginal('cost'))->toBe(200.0);
});

it('removes a service item from a package and deletes orphaned estimate item', function () {
    $package = $this->estimate->packages()->first();

    // First add the addon
    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('openAddonModal', $package->id)
        ->call('addServiceAddon', $this->optionalItem->id);

    $package->refresh();

    // Find the newly added package item
    $addedPackageItem = $package->items()
        ->where('itemable_type', EstimateItem::class)
        ->whereHas('itemable', fn ($q) => $q->where('description', 'Post-Project Cleaning'))
        ->latest()
        ->first();

    expect($addedPackageItem)->not->toBeNull();
    $estimateItemId = $addedPackageItem->itemable_id;

    // Now remove it
    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('removeServiceItem', $package->id, $addedPackageItem->id);

    // Package item should be deleted
    expect(EstimatePackageItem::find($addedPackageItem->id))->toBeNull();

    // Orphaned EstimateItem should also be deleted
    expect(EstimateItem::find($estimateItemId))->toBeNull();
});

it('recalculates package total after adding addon', function () {
    $package = $this->estimate->packages()->first();

    // Mount recalculates totals from actual items, so get the baseline from after mount
    $component = Livewire::test('estimates.package-builder', ['estimate' => $this->estimate]);

    $package->refresh();
    $totalBefore = (float) $package->total;

    $component->call('openAddonModal', $package->id)
        ->call('addServiceAddon', $this->optionalItem->id);

    $package->refresh();
    $totalAfter = (float) $package->total;

    // Total should have increased by the addon price ($400)
    expect($totalAfter - $totalBefore)->toBe(400.0);
});

it('does not show already-added services in addon modal', function () {
    $package = $this->estimate->packages()->first();

    // Add the optional item
    $component = Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('openAddonModal', $package->id)
        ->call('addServiceAddon', $this->optionalItem->id);

    // Reopen the modal - the added item should not appear
    $component->call('openAddonModal', $package->id);
    $addons = $component->get('availableAddons');

    $addonNames = collect($addons)->pluck('name')->toArray();
    expect($addonNames)->not->toContain('Post-Project Cleaning');
});
