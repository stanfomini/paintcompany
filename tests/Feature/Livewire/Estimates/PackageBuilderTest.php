<?php

use App\Livewire\Estimates\PackageBuilder;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\User;
use Livewire\Livewire;

describe('PackageBuilder component', function () {
    beforeEach(function () {
        $this->internalUser = User::factory()->internal()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->internalUser->id,
            'tax_rate' => 8.25,
            'deposit_percent' => 30,
        ]);
    });

    it('renders for internal user', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->assertStatus(200)
            ->assertSee('Provide Package Options');
    });

    it('shows create good/better/best button when no packages exist', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->assertSee('Create Good/Better/Best')
            ->assertSee('Add Package');
    });

    it('hides create good/better/best button when packages exist', function () {
        EstimatePackage::factory()->forEstimate($this->estimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->assertDontSee('Create Good/Better/Best')
            ->assertSee('Add Package');
    });

    it('can create good/better/best packages', function () {
        // Create some items on the estimate to duplicate
        EstimateItem::factory()->forEstimate($this->estimate)->count(2)->create();

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('createGoodBetterBest')
            ->assertDispatched('packages-updated');

        expect($this->estimate->packages()->count())->toBe(3);

        $packages = $this->estimate->packages()->orderBy('sort_order')->get();
        expect($packages[0]->tier)->toBe('basic');
        expect($packages[0]->name)->toBe('Good');
        expect($packages[1]->tier)->toBe('standard');
        expect($packages[1]->name)->toBe('Better');
        expect($packages[1]->is_recommended)->toBeTrue();
        expect($packages[2]->tier)->toBe('premium');
        expect($packages[2]->name)->toBe('Best');
    });

    it('can open package modal for creating new package', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->assertSet('showPackageModal', true)
            ->assertSet('editingPackageId', null)
            ->assertSet('packageName', '')
            ->assertSet('packageTier', 'standard')
            ->assertSet('packageDescription', '')
            ->assertSet('packageDiscountPercent', 0);
    });

    it('can open package modal for editing existing package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Test Package',
            'tier' => 'premium',
            'description' => 'Test description',
            'discount_percent' => 10,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal', $package->id)
            ->assertSet('showPackageModal', true)
            ->assertSet('editingPackageId', $package->id)
            ->assertSet('packageName', 'Test Package')
            ->assertSet('packageTier', 'premium')
            ->assertSet('packageDescription', 'Test description')
            ->assertSet('packageDiscountPercent', 10);
    });

    it('can close package modal', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->assertSet('showPackageModal', true)
            ->call('closePackageModal')
            ->assertSet('showPackageModal', false)
            ->assertSet('editingPackageId', null);
    });

    it('can create new package', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', 'Custom Package')
            ->set('packageTier', 'custom')
            ->set('packageDescription', 'A custom package for special needs')
            ->set('packageDiscountPercent', 5)
            ->call('savePackage')
            ->assertHasNoErrors()
            ->assertSet('showPackageModal', false)
            ->assertDispatched('packages-updated');

        $package = EstimatePackage::where('estimate_id', $this->estimate->id)
            ->where('name', 'Custom Package')
            ->first();

        expect($package)->not->toBeNull();
        expect($package->tier)->toBe('custom');
        expect($package->description)->toBe('A custom package for special needs');
        expect((float) $package->discount_percent)->toBe(5.0);
    });

    it('can update existing package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create([
            'name' => 'Original Name',
            'tier' => 'basic',
            'description' => 'Original description',
            'discount_percent' => 0,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
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

    it('can delete package', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->create();

        expect(EstimatePackage::where('id', $package->id)->exists())->toBeTrue();

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('deletePackage', $package->id)
            ->assertDispatched('packages-updated');

        expect(EstimatePackage::where('id', $package->id)->exists())->toBeFalse();
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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('toggleItemInclusion', $package->id, $packageItem->id)
            ->assertDispatched('packages-updated');

        $packageItem->refresh();
        expect($packageItem->is_included)->toBeFalse();

        // Toggle back
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('markAsRecommended', $package2->id)
            ->assertDispatched('packages-updated');

        $package1->refresh();
        $package2->refresh();

        expect($package1->is_recommended)->toBeFalse();
        expect($package2->is_recommended)->toBeTrue();
    });

    it('validates package name is required', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', '')
            ->call('savePackage')
            ->assertHasErrors(['packageName']);
    });

    it('validates package tier is valid', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', 'Test Package')
            ->set('packageTier', 'invalid-tier')
            ->call('savePackage')
            ->assertHasErrors(['packageTier']);
    });

    it('validates discount percent is not negative', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', 'Test Package')
            ->set('packageDiscountPercent', -5)
            ->call('savePackage')
            ->assertHasErrors(['packageDiscountPercent']);
    });

    it('validates discount percent does not exceed 100', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', 'Test Package')
            ->set('packageDiscountPercent', 150)
            ->call('savePackage')
            ->assertHasErrors(['packageDiscountPercent']);
    });

    it('cannot delete package from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPackage = EstimatePackage::factory()->forEstimate($otherEstimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('deletePackage', $otherPackage->id);

        expect(EstimatePackage::where('id', $otherPackage->id)->exists())->toBeTrue();
    });

    it('cannot edit package from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPackage = EstimatePackage::factory()->forEstimate($otherEstimate)->create([
            'name' => 'Other Package',
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal', $otherPackage->id)
            ->set('packageName', 'Hacked Name')
            ->call('savePackage');

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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate]);

        $packages = $component->get('packages');
        expect($packages)->toHaveCount(1);
        expect($packages[0]['name'])->toBe('Test Package');
        expect($packages[0]['items'])->toHaveCount(1);
        expect($packages[0]['items'][0]['name'])->toBe('Test Item');
    });

    it('calculates package totals correctly', function () {
        $package = EstimatePackage::factory()->forEstimate($this->estimate)->withPricing(
            subtotal: 1000,
            taxRate: 10,
            discountPercent: 10
        )->create();

        $component = Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate]);

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
            ->test(PackageBuilder::class, ['estimate' => $this->estimate]);

        $instance = $component->instance();
        expect($instance->getTierBadgeColor('basic'))->toBe('zinc');
        expect($instance->getTierBadgeColor('standard'))->toBe('blue');
        expect($instance->getTierBadgeColor('premium'))->toBe('amber');
        expect($instance->getTierBadgeColor('custom'))->toBe('purple');
    });

    it('duplicates estimate items to new package', function () {
        // Create items and areas on the estimate
        EstimateItem::factory()->forEstimate($this->estimate)->count(2)->create();
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create();
        EstimateAreaSurface::factory()->forArea($area)->count(2)->create();

        Livewire::actingAs($this->internalUser)
            ->test(PackageBuilder::class, ['estimate' => $this->estimate])
            ->call('openPackageModal')
            ->set('packageName', 'New Package')
            ->call('savePackage')
            ->assertDispatched('packages-updated');

        $package = $this->estimate->packages()->first();
        // Should have: 2 items + 1 area + 2 surfaces = 5 package items
        expect($package->items()->count())->toBe(5);
    });
});
