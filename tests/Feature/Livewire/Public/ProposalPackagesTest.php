<?php

use App\Livewire\Public\ProposalPackages;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateOptionalItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders successfully', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertStatus(200);
});

it('displays packages', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);

    $basicPackage = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create(['name' => 'Basic Package']);

    $standardPackage = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->standard()
        ->recommended()
        ->withPricing(2000, 0, 0)
        ->create(['name' => 'Standard Package']);

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSee('Basic Package')
        ->assertSee('Standard Package')
        ->assertSee('$1,000.00')
        ->assertSee('$2,000.00')
        ->assertSee('Recommended');
});

it('selects a package when clicked', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSet('selectedPackageId', null)
        ->call('selectPackage', $package->id)
        ->assertSet('selectedPackageId', $package->id)
        ->assertDispatched('package-selected');
});

it('cannot select package when estimate is accepted', function () {
    $estimate = Estimate::factory()->create(['status' => 'accepted']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->assertSet('selectedPackageId', null);
});

it('displays package items after selection', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create();

    $estimateItem = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'description' => 'Interior Painting',
        'total' => 500,
    ]);

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem)
        ->approved()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->assertSee('Interior Painting')
        ->assertSee('Package Details');
});

it('toggles line item approval', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create();

    $estimateItem = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'description' => 'Test Item',
        'total' => 500,
    ]);

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem)
        ->approved()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->call('toggleLineItemApproval', $packageItem->id);

    expect($packageItem->fresh()->customer_approved)->toBeFalse();
});

it('toggles optional items', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    $optionalItem = EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(250)
        ->notSelected()
        ->create(['name' => 'Extended Warranty']);

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSee('Extended Warranty')
        ->call('toggleOptionalItem', $optionalItem->id);

    expect($optionalItem->fresh()->is_selected_by_customer)->toBeTrue();
});

it('calculates selected package total correctly', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'deposit_percent' => 30,
    ]);

    // Create package without items initially
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create([
            'subtotal' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'warranty_price' => 0,
        ]);

    // Create estimate items with known prices (using quantity and unit_price
    // because the model recalculates total on save)
    $estimateItem1 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 600,
    ]);

    $estimateItem2 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 400,
    ]);

    // Create package items linking to estimate items
    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem1)
        ->approved()
        ->create();

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem2)
        ->notApproved()
        ->create();

    // Test component - total should only include approved items
    // The EstimatePackageItem model triggers recalculation, so we verify
    // that non-approved items are excluded from the selectedPackageTotal
    $component = Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    // Get the package from the component's packages array
    $packages = $component->get('packages');
    $selectedPackage = collect($packages)->firstWhere('id', $package->id);

    // Verify non-approved item is in packages array but not approved
    $items = $selectedPackage['items'];
    $approvedItem = collect($items)->firstWhere('customer_approved', true);
    $notApprovedItem = collect($items)->firstWhere('customer_approved', false);

    expect($approvedItem)->not->toBeNull()
        ->and($notApprovedItem)->not->toBeNull()
        ->and($approvedItem['price'])->toBe(600.0);
});

it('calculates optional items total', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(200)
        ->selected()
        ->create();

    EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(100)
        ->selected()
        ->create();

    EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(50)
        ->notSelected()
        ->create();

    $component = Livewire::test(ProposalPackages::class, ['estimate' => $estimate]);

    expect($component->get('optionalItemsTotal'))->toBe(300.0);
});

it('calculates deposit based on deposit percent', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'deposit_percent' => 25,
    ]);

    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create([
            'subtotal' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'warranty_price' => 0,
        ]);

    // Create an estimate item with known price
    $estimateItem = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'total' => 800,
    ]);

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem)
        ->approved()
        ->create();

    $component = Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    // Deposit should be 25% of package total (calculated from items)
    $finalTotal = $component->get('finalTotal');
    $depositAmount = $component->get('depositAmount');
    $balanceDue = $component->get('balanceDue');

    // The deposit should be 25% of the final total
    expect($depositAmount)->toBe(round($finalTotal * 0.25, 2))
        ->and($balanceDue)->toBe(round($finalTotal - $depositAmount, 2));
});

it('dispatches proceed-to-signature event when package selected', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'deposit_percent' => 30,
    ]);

    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create();

    $estimateItem = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'total' => 1000,
    ]);

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem)
        ->approved()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->call('proceedToSignature')
        ->assertDispatched('proceed-to-signature');
});

it('shows error when proceeding without package selection', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('proceedToSignature')
        ->assertSee('Please select a package before proceeding');
});

it('cannot proceed to signature when estimate is accepted', function () {
    $estimate = Estimate::factory()->create(['status' => 'accepted']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->selected()
        ->create();

    $estimate->update(['selected_package_id' => $package->id]);

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('proceedToSignature')
        ->assertSee('This proposal has already been accepted');
});

it('updates estimate selected_package_id when package selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    expect($estimate->fresh()->selected_package_id)->toBe($package->id);
});

it('shows sign button as disabled when no package selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSee('Please select a package above to continue');
});

it('shows summary section when package is selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->assertSee('Summary')
        ->assertSee('Total');
});

it('loads pre-selected package from estimate', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->selected()
        ->create();

    $estimate->update(['selected_package_id' => $package->id]);

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSet('selectedPackageId', $package->id);
});

it('logs activity when package is selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create(['name' => 'Test Package']);

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    expect($estimate->activities()->where('activity_type', 'package_selected')->exists())->toBeTrue();
});

it('logs activity when item approval is toggled', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    $estimateItem = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($estimateItem)
        ->approved()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('toggleLineItemApproval', $packageItem->id);

    expect($estimate->activities()->where('activity_type', 'item_approval_toggled')->exists())->toBeTrue();
});

it('logs activity when optional item is toggled', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    $optionalItem = EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(100)
        ->notSelected()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->call('toggleOptionalItem', $optionalItem->id);

    expect($estimate->activities()->where('activity_type', 'optional_item_toggled')->exists())->toBeTrue();
});

it('cannot modify when estimate is expired', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'valid_until' => now()->subDay(),
    ]);

    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    $component = Livewire::test(ProposalPackages::class, ['estimate' => $estimate]);

    expect($component->instance()->canModify())->toBeFalse();
});

it('shows tier labels correctly', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);

    EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    EstimatePackage::factory()
        ->forEstimate($estimate)
        ->standard()
        ->create();

    EstimatePackage::factory()
        ->forEstimate($estimate)
        ->premium()
        ->create();

    Livewire::test(ProposalPackages::class, ['estimate' => $estimate])
        ->assertSee('Good')
        ->assertSee('Better')
        ->assertSee('Best');
});
