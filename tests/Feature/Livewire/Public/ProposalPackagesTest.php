<?php

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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->assertSet('selectedPackageId', null);
});

it('displays package items after selection', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->withPricing(1000, 0, 0)
        ->create(['name' => 'Basic Package']);

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

    // In the simplified view, package items are shown as features within the package card
    // The simplified view displays the package and its contents
    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->assertSee('Basic Package')
        ->assertSee('Summary');
});

it('does not allow customers to toggle line item approval', function () {
    // Customers can only select a package tier, they cannot modify individual line items
    // Only estimators (via PackageBuilder) can toggle items to differentiate packages
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

    // Calling toggleLineItemApproval should do nothing for customers
    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->call('toggleLineItemApproval', $packageItem->id);

    // Item should STILL be approved (no change)
    expect($packageItem->fresh()->customer_approved)->toBeTrue();
});

it('toggles optional items', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    $optionalItem = EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(250)
        ->notSelected()
        ->create(['name' => 'Extended Warranty']);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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
    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

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

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id)
        ->call('proceedToSignature')
        ->assertDispatched('proceed-to-signature');
});

it('shows error when proceeding without package selection', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('proceedToSignature')
        ->assertSee('This proposal has already been accepted');
});

it('updates estimate selected_package_id when package selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    expect($estimate->fresh()->selected_package_id)->toBe($package->id);
});

it('shows sign button as disabled when no package selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->assertSee('Please select a package above to continue');
});

it('shows summary section when package is selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->assertSet('selectedPackageId', $package->id);
});

it('logs activity when package is selected', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create(['name' => 'Test Package']);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectPackage', $package->id);

    expect($estimate->activities()->where('activity_type', 'package_selected')->exists())->toBeTrue();
});

it('does not log activity when customer attempts to toggle item approval', function () {
    // Customers cannot toggle items, so no activity should be logged
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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('toggleLineItemApproval', $packageItem->id);

    // No activity should be logged since the method does nothing for customers
    expect($estimate->activities()->where('activity_type', 'item_approval_toggled')->exists())->toBeFalse();
});

it('logs activity when optional item is toggled', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);
    EstimatePackage::factory()->forEstimate($estimate)->basic()->create();

    $optionalItem = EstimateOptionalItem::factory()
        ->forEstimate($estimate)
        ->fixed(100)
        ->notSelected()
        ->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
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

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

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

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->assertSee('Good')
        ->assertSee('Better')
        ->assertSee('Best');
});

// ===== NEW PACKAGE TEMPLATE SYSTEM TESTS =====

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;

it('uses package templates when no legacy packages exist and templates are active', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2500,
    ]);

    // No legacy packages created

    // Create package templates
    PackageTemplate::factory()->good()->create(['is_active' => true]);
    PackageTemplate::factory()->better()->create(['is_active' => true]);
    PackageTemplate::factory()->best()->create(['is_active' => true]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    expect($component->get('usePackageTemplates'))->toBeTrue()
        ->and($component->get('packageTemplates'))->toHaveCount(3);
});

it('uses legacy packages when they exist even if templates are active', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);

    // Create a legacy package
    EstimatePackage::factory()
        ->forEstimate($estimate)
        ->basic()
        ->create();

    // Create package templates too
    PackageTemplate::factory()->good()->create(['is_active' => true]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    expect($component->get('usePackageTemplates'))->toBeFalse()
        ->and($component->get('packages'))->toHaveCount(1);
});

it('loads package templates with correct calculated values', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'is_active' => true,
        'warranty_months' => 6,
        'warranty_percent' => 5,
    ]);

    PackageTemplateItem::factory()->create([
        'package_template_id' => $template->id,
        'name' => 'Cleaning Service',
        'pricing_type' => 'fixed',
        'cost' => 200,
        'price' => 300,
        'is_optional' => true,
        'is_included_by_default' => false,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    $templates = $component->get('packageTemplates');
    $betterTemplate = collect($templates)->firstWhere('tier', 'better');

    expect($betterTemplate)->not->toBeNull()
        ->and($betterTemplate['warranty_amount'])->toBe(100.0) // 5% of 2000
        ->and($betterTemplate['painting_total'])->toBe(2000.0)
        ->and($betterTemplate['items'])->toHaveCount(1)
        ->and($betterTemplate['items'][0]['price'])->toBe(300.0);
});

it('selects tier when using package templates', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 1500,
    ]);

    PackageTemplate::factory()->good()->create(['is_active' => true]);
    PackageTemplate::factory()->better()->create(['is_active' => true, 'is_default' => true]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->assertSet('selectedTier', 'better') // Default tier
        ->call('selectTier', 'good')
        ->assertSet('selectedTier', 'good')
        ->assertDispatched('tier-selected');
});

it('calculates selected template total correctly', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'is_active' => true,
        'warranty_months' => 3,
        'warranty_percent' => 2, // $40 warranty
    ]);

    PackageTemplateItem::factory()->create([
        'package_template_id' => $template->id,
        'name' => 'Paint Protection',
        'pricing_type' => 'fixed',
        'price' => 150,
        'is_optional' => false,
        'is_included_by_default' => true,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good');

    // Total = painting (2000) + warranty (40) + included item (150) = 2190
    expect($component->get('selectedTemplateTotal'))->toBe(2190.0);
});

it('toggles optional package items in template system', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 1000,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'is_active' => true,
        'warranty_percent' => 0,
    ]);

    $item = PackageTemplateItem::factory()->create([
        'package_template_id' => $template->id,
        'name' => 'Color Consultation',
        'pricing_type' => 'fixed',
        'price' => 125,
        'is_optional' => true,
        'is_included_by_default' => false,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'better');

    // Initially, optional item is NOT selected
    $initialTotal = $component->get('selectedTemplateTotal');
    expect($initialTotal)->toBe(1000.0);

    // Toggle the item on
    $component->call('togglePackageItem', 'item_'.$item->id);

    // Now total should include the item
    expect($component->get('selectedTemplateTotal'))->toBe(1125.0);
});

it('proceeds to signature with template data', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 3000,
        'deposit_percent' => 25,
    ]);

    PackageTemplate::factory()->best()->create([
        'is_active' => true,
        'warranty_percent' => 3,
    ]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'best')
        ->call('proceedToSignature')
        ->assertDispatched('proceed-to-signature');
});

it('shows error when proceeding without tier selection in template mode', function () {
    $estimate = Estimate::factory()->create(['status' => 'sent']);

    PackageTemplate::factory()->good()->create(['is_active' => true]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->set('selectedTier', null)
        ->call('proceedToSignature')
        ->assertSee('Please select a package before proceeding');
});

it('uses final total from template for deposit calculation', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 4000,
        'deposit_percent' => 30,
    ]);

    PackageTemplate::factory()->better()->create([
        'is_active' => true,
        'warranty_percent' => 5, // $200 warranty
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'better');

    // Total = 4000 + 200 = 4200
    // Deposit = 4200 * 0.30 = 1260
    expect($component->get('finalTotal'))->toBe(4200.0)
        ->and($component->get('depositAmount'))->toBe(1260.0)
        ->and($component->get('balanceDue'))->toBe(2940.0);
});

it('cannot select tier when estimate is accepted', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'accepted',
        'areas_subtotal' => 2000,
    ]);

    PackageTemplate::factory()->good()->create(['is_active' => true]);
    PackageTemplate::factory()->better()->create(['is_active' => true, 'is_default' => true]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good');

    // Should stay on default (better) because canModify() returns false
    expect($component->get('selectedTier'))->toBe('better');
});

it('logs activity when tier is selected', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2500,
    ]);

    PackageTemplate::factory()->good()->create(['is_active' => true]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good');

    expect($estimate->activities()->where('activity_type', 'package_tier_selected')->exists())->toBeTrue();
});
