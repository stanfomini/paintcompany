<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\PackageTemplate;
use App\Models\User;
use App\Notifications\EstimatePackageUpdated;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('PackageBuilder component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->internalUser->id,
            'tax_rate' => 8.25,
            'deposit_percent' => 30,
        ]);
    });

    it('renders for internal user', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->assertStatus(200)
            ->assertSee('Pricing Packages (Good/Better/Best)');
    });

    it('shows link to configure templates when no packages and no templates exist', function () {
        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->assertSee('No package templates configured')
            ->assertSee('Configure Package Templates');
    });

    it('auto-generates packages from templates when none exist', function () {
        // Create package templates
        PackageTemplate::factory()->good()->create(['is_active' => true]);
        PackageTemplate::factory()->better()->create(['is_active' => true, 'is_default' => true]);
        PackageTemplate::factory()->best()->create(['is_active' => true]);

        // Create some items on the estimate
        EstimateItem::factory()->forEstimate($this->estimate)->count(2)->create();

        // Mount should auto-generate packages
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        expect($this->estimate->packages()->count())->toBe(3);

        $packages = $this->estimate->packages()->orderBy('sort_order')->get();
        expect($packages[0]->tier)->toBe('basic');
        expect($packages[1]->tier)->toBe('standard');
        expect($packages[2]->tier)->toBe('premium');
    });

    it('shows regenerate button when templates are configured', function () {
        PackageTemplate::factory()->good()->create(['is_active' => true]);
        EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->assertSee('Regenerate from Templates');
    });

    it('can regenerate packages from templates', function () {
        // Create templates
        PackageTemplate::factory()->good()->create(['is_active' => true, 'name' => 'Good Package']);
        PackageTemplate::factory()->better()->create(['is_active' => true, 'name' => 'Better Package']);

        // Create an existing package
        EstimatePackage::factory()->forEstimate($this->estimate)->create(['name' => 'Old Package']);
        expect($this->estimate->packages()->count())->toBe(1);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('regeneratePackages')
            ->assertDispatched('packages-updated');

        // Should have 2 packages from templates now
        expect($this->estimate->fresh()->packages()->count())->toBe(2);
    });

    it('can open package modal for editing existing package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Test Package',
            'tier' => 'premium',
            'description' => 'Test description',
            'discount_percent' => 10,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->assertSet('showPackageModal', true)
            ->assertSet('editingPackageId', $package->id)
            ->assertSet('packageName', 'Test Package')
            ->assertSet('packageTier', 'premium')
            ->assertSet('packageDescription', 'Test description')
            ->assertSet('packageDiscountPercent', 10);
    });

    it('can close package modal', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->assertSet('showPackageModal', true)
            ->call('closePackageModal')
            ->assertSet('showPackageModal', false)
            ->assertSet('editingPackageId', null);
    });

    it('can update existing package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Original Name',
            'tier' => 'basic',
            'description' => 'Original description',
            'discount_percent' => 0,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageName', 'Updated Name')
            ->set('packageTier', 'premium')
            ->set('packageDescription', 'Updated description')
            ->set('packageDiscountPercent', 15)
            ->call('savePackage')
            ->assertHasNoErrors()
            ->assertDispatched('packages-updated');

        $package->refresh();
        expect($package->name)->toBe('Updated Name');
        expect($package->tier)->toBe('premium');
        expect($package->description)->toBe('Updated description');
        expect((float) $package->discount_percent)->toBe(15.0);
    });

    it('does nothing when savePackage called without editing package id', function () {
        $initialCount = $this->estimate->packages()->count();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->set('packageName', 'New Package')
            ->call('savePackage');

        // Should not create a new package
        expect($this->estimate->packages()->count())->toBe($initialCount);
    });

    it('can toggle item inclusion in package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();
        $item = EstimateItem::factory()->forEstimate($this->estimate)->create();
        $packageItem = EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateItem($item)
            ->create(['is_included' => true]);

        expect($packageItem->is_included)->toBeTrue();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id)
            ->assertDispatched('packages-updated');

        $packageItem->refresh();
        expect($packageItem->is_included)->toBeFalse();

        // Toggle back
        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        $packageItem->refresh();
        expect($packageItem->is_included)->toBeTrue();
    });

    it('can mark package as recommended', function () {
        $package1 = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'is_recommended' => true,
        ]);
        $package2 = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'is_recommended' => false,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('markAsRecommended', $package2->id)
            ->assertDispatched('packages-updated');

        $package1->refresh();
        $package2->refresh();

        expect($package1->is_recommended)->toBeFalse();
        expect($package2->is_recommended)->toBeTrue();
    });

    it('validates package name is required', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageName', '')
            ->call('savePackage')
            ->assertHasErrors(['packageName']);
    });

    it('validates package tier is valid (only basic, standard, premium)', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageTier', 'custom')
            ->call('savePackage')
            ->assertHasErrors(['packageTier']);
    });

    it('validates discount percent is not negative', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageDiscountPercent', -5)
            ->call('savePackage')
            ->assertHasErrors(['packageDiscountPercent']);
    });

    it('validates discount percent does not exceed 100', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageDiscountPercent', 150)
            ->call('savePackage')
            ->assertHasErrors(['packageDiscountPercent']);
    });

    it('cannot edit package from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPackage = EstimatePackage::factory()->forEstimate($otherEstimate)->create([
            'name' => 'Other Package',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $otherPackage->id)
            ->assertSet('showPackageModal', false); // Should not open

        $otherPackage->refresh();
        expect($otherPackage->name)->toBe('Other Package');
    });

    it('cannot toggle item inclusion for package from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPackage = EstimatePackage::factory()->forEstimate($otherEstimate)->create();
        $item = EstimateItem::factory()->forEstimate($otherEstimate)->create();
        $packageItem = EstimatePackageItem::factory()
            ->forPackage($otherPackage)
            ->forEstimateItem($item)
            ->create(['is_included' => true]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $otherPackage->id, $packageItem->id);

        $packageItem->refresh();
        expect($packageItem->is_included)->toBeTrue();
    });

    it('cannot mark package from another estimate as recommended', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPackage = EstimatePackage::factory()->forEstimate($otherEstimate)->create([
            'is_recommended' => false,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('markAsRecommended', $otherPackage->id);

        $otherPackage->refresh();
        expect($otherPackage->is_recommended)->toBeFalse();
    });

    it('loads packages with items from estimate', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Test Package',
        ]);
        $item = EstimateItem::factory()->forEstimate($this->estimate)->create([
            'description' => 'Test Item',
        ]);
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateItem($item)
            ->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        $packages = $component->get('packages');
        expect($packages)->toHaveCount(1);
        expect($packages[0]['name'])->toBe('Test Package');
        // Services array should contain the item
        expect($packages[0]['services'])->toHaveCount(1);
        expect($packages[0]['services'][0]['name'])->toBe('Test Item');
    });

    it('calculates package totals correctly', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->withPricing(
            subtotal: 0,
            taxRate: 10,
            discountPercent: 10
        )->create([
            'warranty_price' => 0,
        ]);

        // Create a service item worth $1000 and link it to the package
        // Must set quantity and unit_price since saving hook calculates total = quantity * unit_price
        $item = EstimateItem::factory()->forEstimate($this->estimate)->create([
            'quantity' => 1,
            'unit_price' => 1000,
        ]);
        EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateItem($item)
            ->create(['is_included' => true, 'customer_approved' => true]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        $packages = $component->get('packages');
        expect($packages[0]['subtotal'])->toBe(1000.0);
        expect($packages[0]['discount_percent'])->toBe(10.0);
        // Discount: 1000 * 10% = 100
        // Taxable: 1000 - 100 = 900
        // Tax: 900 * 10% = 90
        // Total: 900 + 90 = 990
        expect($packages[0]['total'])->toBe(990.0);
    });

    it('returns correct tier badge color', function () {
        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        $instance = $component->instance();
        expect($instance->getTierBadgeColor('basic'))->toBe('zinc');
        expect($instance->getTierBadgeColor('standard'))->toBe('blue');
        expect($instance->getTierBadgeColor('premium'))->toBe('amber');
    });
});

describe('PackageBuilder notifications for sent estimates', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->customerUser = User::factory()->customer()->create();
        $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create([
            'created_by' => $this->internalUser->id,
        ]);
        // Note: customer is accessed through Deal->customer, not estimate.customer_id
        $this->estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->internalUser->id,
            'status' => 'sent',
        ]);
    });

    it('notifies customer when package is edited on sent estimate', function () {
        Notification::fake();

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Original Name',
            'discount_percent' => 0,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageName', 'Updated Name')
            ->set('packageDiscountPercent', 10)
            ->call('savePackage');

        // Customer model is notifiable
        Notification::assertSentTo($this->customer, EstimatePackageUpdated::class);
    });

    it('logs activity when package is modified on sent estimate', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Original Name',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageName', 'Updated Name')
            ->call('savePackage');

        expect(EstimateActivity::where('estimate_id', $this->estimate->id)
            ->where('activity_type', 'packages_modified')
            ->exists())->toBeTrue();
    });

    it('notifies customer when item is toggled on sent estimate', function () {
        Notification::fake();

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();
        $item = EstimateItem::factory()->forEstimate($this->estimate)->create();
        $packageItem = EstimatePackageItem::factory()
            ->forPackage($package)
            ->forEstimateItem($item)
            ->create(['is_included' => true]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        Notification::assertSentTo($this->customer, EstimatePackageUpdated::class);
    });

    it('notifies customer when packages are regenerated on sent estimate', function () {
        Notification::fake();

        PackageTemplate::factory()->good()->create(['is_active' => true]);
        EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('regeneratePackages');

        Notification::assertSentTo($this->customer, EstimatePackageUpdated::class);
    });

    it('does not notify customer when package is edited on draft estimate', function () {
        Notification::fake();

        $this->estimate->update(['status' => 'draft']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Original Name',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->set('packageName', 'Updated Name')
            ->call('savePackage');

        Notification::assertNotSentTo($this->customer, EstimatePackageUpdated::class);
    });
});

describe('area breakdown within packages', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->estimate = Estimate::factory()->create([
            'created_by' => $this->internalUser->id,
            'status' => 'draft',
        ]);
    });

    it('groups surface items by area in packages', function () {
        // Create a package with surface items
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Test Package',
        ]);

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Living Room',
        ]);

        $surface1 = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'quantity' => 400,
            'total' => 200.00,
            'is_included' => true,
        ]);

        $surface2 = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Ceiling',
            'quantity' => 200,
            'total' => 100.00,
            'is_included' => true,
        ]);

        // Link surfaces to package
        EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface1)->create(['is_included' => true]);
        EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface2)->create(['is_included' => true]);

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        $packages = $component->get('packages');

        expect($packages)->toHaveCount(1);
        expect($packages[0]['areas'])->toHaveCount(1);
        expect($packages[0]['areas'][0]['name'])->toBe('Living Room');
        expect($packages[0]['areas'][0]['surfaces'])->toHaveCount(2);
    });

    it('shows area name in package when surfaces exist', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Test Package',
        ]);

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Kitchen',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'is_included' => true,
        ]);

        EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create(['is_included' => true]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->assertSee('Kitchen');
    });

    it('can toggle area expansion per package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Bedroom',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'is_included' => true,
        ]);

        EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate]);

        // Initially not expanded
        expect($component->get('expandedAreas'))->toBeEmpty();

        // Toggle expansion (now takes packageId and areaId)
        $component->call('toggleAreaExpansion', $package->id, $area->id);

        $key = "{$package->id}-{$area->id}";
        expect($component->get('expandedAreas')[$key])->toBeTrue();

        // Toggle again to collapse
        $component->call('toggleAreaExpansion', $package->id, $area->id);

        expect($component->get('expandedAreas')[$key])->toBeFalse();
    });

    it('can toggle surface inclusion via package item', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Bathroom',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'is_included' => true,
        ]);

        $packageItem = EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
            'is_included' => true,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        $packageItem->refresh();
        expect($packageItem->is_included)->toBeFalse();
    });

    it('dispatches packages-updated event when surface item is toggled', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create();

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'is_included' => true,
        ]);

        $packageItem = EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
            'is_included' => true,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id)
            ->assertDispatched('packages-updated');
    });
});

describe('surface item toggle notifications', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->withFullAccess()->create();
        $this->customerUser = User::factory()->customer()->create();
        $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create([
            'created_by' => $this->internalUser->id,
        ]);
        $this->estimate = Estimate::factory()->forDeal($this->deal)->create([
            'created_by' => $this->internalUser->id,
            'status' => 'sent',
        ]);
    });

    it('notifies customer when surface item is toggled on sent estimate', function () {
        Notification::fake();

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Living Room',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Walls',
            'is_included' => true,
        ]);

        $packageItem = EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
            'is_included' => true,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        Notification::assertSentTo($this->customer, EstimatePackageUpdated::class);
    });

    it('does not notify customer when surface item is toggled on draft estimate', function () {
        Notification::fake();

        $this->estimate->update(['status' => 'draft']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Kitchen',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Ceiling',
            'is_included' => true,
        ]);

        $packageItem = EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
            'is_included' => true,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        Notification::assertNotSentTo($this->customer, EstimatePackageUpdated::class);
    });

    it('logs activity when surface item is toggled on sent estimate', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        $area = \App\Models\EstimateArea::factory()->forEstimate($this->estimate)->create([
            'name' => 'Bedroom',
        ]);

        $surface = \App\Models\EstimateAreaSurface::factory()->forArea($area)->create([
            'name' => 'Baseboards',
            'is_included' => true,
        ]);

        $packageItem = EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
            'is_included' => true,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test('estimates.package-builder', ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id);

        expect(EstimateActivity::where('estimate_id', $this->estimate->id)
            ->where('activity_type', 'packages_modified')
            ->exists())->toBeTrue();
    });
});

describe('package totals recalculation', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->user->id,
        ]);
    });

    it('recalculates package totals when estimate item changes', function () {
        $this->actingAs($this->user);

        // Create an estimate item
        $item = EstimateItem::factory()->create([
            'estimate_id' => $this->estimate->id,
            'total' => 100,
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        // Create a package with the item
        $package = EstimatePackage::factory()->create([
            'estimate_id' => $this->estimate->id,
            'tier' => 'standard',
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        // Calculate initial package total
        $package->calculateTotals();
        $package->save();
        $initialSubtotal = (float) $package->refresh()->subtotal;

        // Wait to bypass the time guard
        sleep(2);

        // Update the item's price
        $item->update([
            'total' => 250,
            'unit_price' => 250,
        ]);

        // Package should be recalculated (via model callback)
        $package->refresh();

        // The package subtotal should reflect the new item price
        expect((float) $package->subtotal)->toBe(250.0);
    });
});
