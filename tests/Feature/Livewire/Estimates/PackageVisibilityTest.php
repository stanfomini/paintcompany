<?php

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\PackageTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);

    $this->estimate = Estimate::factory()->create(['status' => 'draft']);

    $this->basicPackage = EstimatePackage::factory()->basic()->forEstimate($this->estimate)->create();
    $this->standardPackage = EstimatePackage::factory()->standard()->recommended()->forEstimate($this->estimate)->create();
    $this->premiumPackage = EstimatePackage::factory()->premium()->forEstimate($this->estimate)->create();
});

it('can toggle package visibility off', function () {
    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $this->premiumPackage->id);

    expect($this->premiumPackage->fresh()->is_visible)->toBeFalse();
});

it('can toggle package visibility back on', function () {
    $this->premiumPackage->update(['is_visible' => false]);

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $this->premiumPackage->id);

    expect($this->premiumPackage->fresh()->is_visible)->toBeTrue();
});

it('cannot hide all packages', function () {
    $this->basicPackage->update(['is_visible' => false]);
    $this->premiumPackage->update(['is_visible' => false]);

    // Only standardPackage is visible - trying to hide it should fail
    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $this->standardPackage->id);

    expect($this->standardPackage->fresh()->is_visible)->toBeTrue();
});

it('deselects hidden package when it was selected', function () {
    $this->standardPackage->update(['is_selected' => true]);
    $this->estimate->update(['selected_package_id' => $this->standardPackage->id]);

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $this->standardPackage->id);

    expect($this->standardPackage->fresh()->is_selected)->toBeFalse();
    expect($this->estimate->fresh()->selected_package_id)->toBeNull();
});

it('moves recommended to first visible when hiding recommended package', function () {
    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $this->standardPackage->id);

    // standardPackage was recommended and is now hidden
    expect($this->standardPackage->fresh()->is_recommended)->toBeFalse();
    // basicPackage is first visible by sort_order and should now be recommended
    expect($this->basicPackage->fresh()->is_recommended)->toBeTrue();
});

it('shows only visible packages to customer on proposal', function () {
    $this->premiumPackage->update(['is_visible' => false]);
    $customer = User::factory()->customer()->create();

    $component = Livewire::actingAs($customer)
        ->test('public.proposal-packages', ['estimate' => $this->estimate]);

    // Should have 2 packages (basic + standard), not 3
    $packages = $component->get('packages');
    expect($packages)->toHaveCount(2);
    expect(collect($packages)->pluck('id')->toArray())->not->toContain($this->premiumPackage->id);
});

it('auto-selects single visible package on proposal', function () {
    $this->basicPackage->update(['is_visible' => false]);
    $this->premiumPackage->update(['is_visible' => false]);
    $customer = User::factory()->customer()->create();

    $component = Livewire::actingAs($customer)
        ->test('public.proposal-packages', ['estimate' => $this->estimate]);

    expect($component->get('selectedPackageId'))->toBe($this->standardPackage->id);
});

it('shows single-package header text on legacy proposal', function () {
    $this->basicPackage->update(['is_visible' => false]);
    $this->premiumPackage->update(['is_visible' => false]);
    $customer = User::factory()->customer()->create();

    Livewire::actingAs($customer)
        ->test('public.proposal-packages', ['estimate' => $this->estimate])
        ->assertSee(__('Your Proposal'))
        ->assertDontSee(__('Choose Your Package'));
});

it('cannot toggle packages from another estimate', function () {
    $otherEstimate = Estimate::factory()->create();
    $otherPackage = EstimatePackage::factory()->basic()->forEstimate($otherEstimate)->create();

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('toggleVisibility', $otherPackage->id);

    // Should remain unchanged
    expect($otherPackage->fresh()->is_visible)->toBeTrue();
});

it('regeneration resets all packages to visible', function () {
    $this->premiumPackage->update(['is_visible' => false]);

    // Need active templates for regeneration
    PackageTemplate::factory()->create([
        'tier' => 'good',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    PackageTemplate::factory()->create([
        'tier' => 'better',
        'is_active' => true,
        'sort_order' => 2,
    ]);
    PackageTemplate::factory()->create([
        'tier' => 'best',
        'is_active' => true,
        'sort_order' => 3,
    ]);

    Livewire::test('estimates.package-builder', ['estimate' => $this->estimate])
        ->call('regeneratePackages');

    // All regenerated packages should be visible
    $packages = $this->estimate->fresh()->packages;
    expect($packages->every(fn ($p) => $p->is_visible))->toBeTrue();
});

it('includes is_visible in package builder data', function () {
    $this->premiumPackage->update(['is_visible' => false]);

    $component = Livewire::test('estimates.package-builder', ['estimate' => $this->estimate]);

    $packages = $component->get('packages');
    $premium = collect($packages)->firstWhere('id', $this->premiumPackage->id);
    expect($premium['is_visible'])->toBeFalse();

    $standard = collect($packages)->firstWhere('id', $this->standardPackage->id);
    expect($standard['is_visible'])->toBeTrue();
});

it('prevents customer from selecting hidden package', function () {
    $this->premiumPackage->update(['is_visible' => false]);
    $customer = User::factory()->customer()->create();

    Livewire::actingAs($customer)
        ->test('public.proposal-packages', ['estimate' => $this->estimate])
        ->call('selectPackage', $this->premiumPackage->id);

    expect($this->estimate->fresh()->selected_package_id)->not->toBe($this->premiumPackage->id);
});
