<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\NestedAreaTemplate;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use App\Services\EstimateCostBreakdownService;
use Livewire\Livewire;

beforeEach(function () {
    // Ensure clean company settings for consistent calculations
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
});

describe('Cost Breakdown Access Control', function () {
    it('allows internal users to access the breakdown page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.breakdown', $estimate))
            ->assertStatus(200);
    });

    it('redirects agent users to their dashboard', function () {
        $user = User::factory()->agent()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.breakdown', $estimate))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects customer users to their dashboard', function () {
        $user = User::factory()->customer()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.breakdown', $estimate))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('denies access to unauthenticated users', function () {
        $estimate = Estimate::factory()->create();

        $this->get(route('estimates.breakdown', $estimate))
            ->assertRedirect(route('login'));
    });
});

describe('Cost Breakdown Component', function () {
    it('renders the cost breakdown component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Cost Breakdown')
            ->assertSee($estimate->estimate_number);
    });

    it('displays internal only warning', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSee('Internal Only');
    });

    it('shows summary cards with key metrics', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSee('Customer Price')
            ->assertSee('Gross Profit')
            ->assertSee('Net Profit')
            ->assertSee('Target Delta');
    });

    it('can toggle what-if slider', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSet('showWhatIf', false)
            ->call('toggleWhatIf')
            ->assertSet('showWhatIf', true)
            ->call('toggleWhatIf')
            ->assertSet('showWhatIf', false);
    });

    it('updates what-if margin and recalculates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate]);

        // Get initial margin
        $initialMargin = $component->get('whatIfMargin');

        // Update margin
        $component->call('updateWhatIfMargin', 35)
            ->assertSet('whatIfMargin', 35);

        // Boundary check - should clamp to 0-100
        $component->call('updateWhatIfMargin', -10)
            ->assertSet('whatIfMargin', 0);

        $component->call('updateWhatIfMargin', 150)
            ->assertSet('whatIfMargin', 100);
    });

    it('can switch between tabs when original snapshot exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
            'settings_snapshot' => [
                'captured_at' => now()->subDays(7)->toIso8601String(),
                'scope' => 'interior',
                'hourly_wage' => 21.00,
                'payroll_burden_multiplier' => 1.35,
                'margin_percent' => 50.00,
                'net_profit_margin_percent' => 30.00,
                'overhead_percent' => 12.00,
                'marketing_percent' => 8.00,
                'sales_percent' => 7.00,
                'production_management_percent' => 7.00,
                'cleanup_hours_ratio' => 16.00,
                'sundries_cost_per_hour' => 3.30,
                'material_tax_rate' => 7.5,
                'labor_tax_rate' => 0,
            ],
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSet('activeTab', 'current')
            ->call('setActiveTab', 'comparison')
            ->assertSet('activeTab', 'comparison');
    });
});

describe('Cost Breakdown Service Calculations', function () {
    it('calculates COGS from area model methods', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        $category = ProductionRateCategory::factory()->create(['name' => 'Interior']);
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
            'name' => 'Walls - Standard',
            'production_rate' => 200,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'wall_sqft' => 400,
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'quantity' => 400,
            'labor_hours' => 2.0,
            'labor_cost' => 42.00,
            'material_cost' => 50.00,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Verify new data structure
        expect($breakdown)->toHaveKey('cogs');
        expect($breakdown)->toHaveKey('pricing');
        expect($breakdown)->toHaveKey('gross_profit');
        expect($breakdown)->toHaveKey('allocations');
        expect($breakdown)->toHaveKey('net_profit');
        expect($breakdown)->toHaveKey('areas');

        // COGS comes from area model (getFullCOGS)
        expect($breakdown['cogs']['total'])->toBeGreaterThan(0);

        // Area breakdown should have cogs and customer_price from model
        expect($breakdown['areas'][0])->toHaveKey('cogs');
        expect($breakdown['areas'][0])->toHaveKey('customer_price');
        expect($breakdown['areas'][0])->toHaveKey('margin_percent');
        expect($breakdown['areas'][0])->toHaveKey('detail');

        // Area COGS should match the model method
        $modelCogs = $area->fresh()->getFullCOGS();
        expect($breakdown['areas'][0]['cogs'])->toEqual(round($modelCogs, 2));

        // Area customer price should match the model method
        $modelPrice = $area->fresh()->getCustomerPrice();
        expect($breakdown['areas'][0]['customer_price'])->toEqual(round($modelPrice, 2));
    });

    it('decomposes COGS into component detail', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
            'production_rate' => 200,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 10.0,
            'labor_cost' => 210.00,
            'material_cost' => 100.00,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Detail should decompose components
        $detail = $breakdown['areas'][0]['detail'];
        expect($detail)->toHaveKey('labor_hours');
        expect($detail)->toHaveKey('cleanup_hours');
        expect($detail)->toHaveKey('labor_cost');
        expect($detail)->toHaveKey('cleanup_cost');
        expect($detail)->toHaveKey('sundries_cost');
        expect($detail)->toHaveKey('material_cost');

        expect($detail['labor_hours'])->toEqual(10.0);
        expect($detail['labor_cost'])->toEqual(210.00);
        expect($detail['material_cost'])->toEqual(100.00);

        // Cleanup hours should be > 0 (based on cleanup ratio)
        expect($detail['cleanup_hours'])->toBeGreaterThan(0);
    });

    it('calculates allocations correctly', function () {
        $service = new EstimateCostBreakdownService;

        $customerPrice = 1000.00;
        $settings = [
            'overhead_percent' => 12.00,
            'marketing_percent' => 8.00,
            'sales_percent' => 7.00,
            'production_management_percent' => 7.00,
        ];

        $allocations = $service->calculateOverheadAllocations($customerPrice, $settings);

        // Marketing, sales, prod mgmt are % of customer price
        expect($allocations['marketing']['amount'])->toEqual(80.00);
        expect($allocations['sales']['amount'])->toEqual(70.00);
        expect($allocations['production_management']['amount'])->toEqual(70.00);
        // Overhead is informational only, not included in total
        expect($allocations['overhead']['amount'])->toEqual(120.00);
        expect($allocations['overhead']['informational'])->toBeTrue();
        // Total = 80 + 70 + 70 = 220 (overhead excluded)
        expect($allocations['total'])->toEqual(220.00);
        // Total percent = 8 + 7 + 7 = 22
        expect($allocations['total_percent'])->toEqual(22.00);
    });

    it('calculates required margin for target net profit', function () {
        $service = new EstimateCostBreakdownService;

        $targetNetMargin = 30.00;
        $costs = [
            'cogs' => 120.00,
        ];
        $settings = [
            'overhead_percent' => 12.00,
            'marketing_percent' => 8.00,
            'sales_percent' => 7.00,
            'production_management_percent' => 7.00,
        ];

        $result = $service->calculateRequiredMarginForTarget($targetNetMargin, $costs, $settings);

        expect($result['is_achievable'])->toBeTrue();
        expect($result['required_margin_percent'])->toBeGreaterThan(0);
        expect($result['required_customer_price'])->toBeGreaterThan($costs['cogs']);

        // Verify the calculation by checking the resulting net margin
        expect($result['verification']['net_margin_percent'])
            ->toBeGreaterThanOrEqual($targetNetMargin - 0.5);
    });

    it('compares snapshots correctly', function () {
        $service = new EstimateCostBreakdownService;

        $original = [
            'captured_at' => now()->subDays(30)->toIso8601String(),
            'scope' => 'interior',
            'hourly_wage' => 21.00,
            'margin_percent' => 45.00,
            'overhead_percent' => 12.00,
        ];

        $current = [
            'captured_at' => now()->toIso8601String(),
            'scope' => 'interior',
            'hourly_wage' => 23.00, // Changed
            'margin_percent' => 50.00, // Changed
            'overhead_percent' => 12.00, // Same
        ];

        $comparison = $service->compareSnapshots($original, $current);

        expect($comparison['has_changes'])->toBeTrue();
        expect($comparison['comparisons']['hourly_wage']['changed'])->toBeTrue();
        expect($comparison['comparisons']['hourly_wage']['delta'])->toEqual(2.00);
        expect($comparison['comparisons']['margin_percent']['changed'])->toBeTrue();
        expect($comparison['comparisons']['overhead_percent']['changed'])->toBeFalse();
    });

    it('handles what-if scenarios', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 10.0,
            'labor_cost' => 210.00,
            'material_cost' => 100.00,
            'is_included' => true,
        ]);

        // Get current breakdown
        $current = $service->calculateBreakdown($estimate);

        // Get what-if with different margin
        $whatIf = $service->calculateWhatIfScenario($estimate, 60.00);

        // Higher margin% means COGS is a higher % of price, so LOWER customer price
        // PBP formula: Price = COGS / (margin% / 100)
        expect($whatIf['pricing']['customer_price'])
            ->toBeLessThan($current['pricing']['customer_price']);

        // Margin percent should be the new value
        expect($whatIf['pricing']['margin_percent'])->toEqual(60.00);
    });
});

describe('Line Items in Breakdown', function () {
    it('includes line items in COGS', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        // Create line items (quantity * unit_price = total, model recalculates)
        $estimate->items()->create([
            'description' => 'Primer coat for new drywall',
            'quantity' => 5,
            'unit' => 'gal',
            'unit_price' => 45.00,
            'sort_order' => 1,
        ]);

        $estimate->items()->create([
            'description' => 'Caulking and patching',
            'quantity' => 1,
            'unit' => 'job',
            'unit_price' => 700.00,
            'sort_order' => 2,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should include items in breakdown
        expect($breakdown)->toHaveKey('items');
        expect($breakdown)->toHaveKey('items_total');
        expect(count($breakdown['items']))->toBe(2);
        expect($breakdown['items_total'])->toEqual(925.00);

        // Items should be included in COGS (vendor cost = total when no cost set)
        expect($breakdown['cogs']['items_total'])->toEqual(925.00);
        expect($breakdown['cogs']['items_vendor_cost'])->toEqual(925.00);
        expect($breakdown['cogs']['total'])->toBeGreaterThanOrEqual(925.00);
    });

    it('tracks vendor cost separately from customer price', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Create a third-party service item with vendor cost
        // House cleaning: customer pays $400, vendor gets $350, profit = $50
        $estimate->items()->create([
            'description' => 'House Cleaning',
            'quantity' => 1,
            'unit' => 'service',
            'unit_price' => 400.00,
            'cost' => 350.00, // Vendor cost
            'sort_order' => 1,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Customer price should include full item total
        expect($breakdown['items_total'])->toEqual(400.00);

        // COGS should only include vendor cost
        expect($breakdown['cogs']['items_total'])->toEqual(400.00);
        expect($breakdown['cogs']['items_vendor_cost'])->toEqual(350.00);
        expect($breakdown['cogs']['items_profit'])->toEqual(50.00);

        // Item breakdown should show vendor cost details
        expect($breakdown['items'][0]['total'])->toEqual(400.00);
        expect($breakdown['items'][0]['vendor_cost'])->toEqual(350.00);
        expect($breakdown['items'][0]['profit'])->toEqual(50.00);
        expect($breakdown['items'][0]['has_vendor_cost'])->toBeTrue();
    });

    it('defaults vendor cost to total when not set', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Create item without explicit vendor cost
        $estimate->items()->create([
            'description' => 'Paint Materials',
            'quantity' => 10,
            'unit' => 'gallon',
            'unit_price' => 50.00,
            'sort_order' => 1,
            // cost is null - should default to total
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Vendor cost should equal total (no profit on this item)
        expect($breakdown['cogs']['items_total'])->toEqual(500.00);
        expect($breakdown['cogs']['items_vendor_cost'])->toEqual(500.00);
        expect($breakdown['cogs']['items_profit'])->toEqual(0.00);

        expect($breakdown['items'][0]['has_vendor_cost'])->toBeFalse();
    });

    it('shows zero items total when no line items exist', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['items'])->toBeEmpty();
        expect($breakdown['items_total'])->toEqual(0.0);
        expect($breakdown['cogs']['items_total'])->toEqual(0.0);
    });
});

describe('Reconciliation', function () {
    it('shows reconciliation between stored and calculated prices', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
            'subtotal' => 5000.00,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown)->toHaveKey('reconciliation');
        expect($breakdown['reconciliation'])->toHaveKey('stored_subtotal');
        expect($breakdown['reconciliation'])->toHaveKey('calculated_price');
        expect($breakdown['reconciliation'])->toHaveKey('difference');
        expect($breakdown['reconciliation']['stored_subtotal'])->toEqual(5000.00);
    });
});

describe('Settings Snapshot on Estimate Creation', function () {
    it('captures settings snapshot when estimate is created', function () {
        $deal = Deal::factory()->create();
        $user = User::factory()->internal()->withFullAccess()->create();

        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $user->id,
            'project_type' => 'interior',
        ]);

        expect($estimate->settings_snapshot)->not->toBeNull();
        expect($estimate->settings_snapshot)->toHaveKey('captured_at');
        expect($estimate->settings_snapshot)->toHaveKey('scope');
        expect($estimate->settings_snapshot)->toHaveKey('hourly_wage');
        expect($estimate->settings_snapshot)->toHaveKey('margin_percent');
        expect($estimate->settings_snapshot)->toHaveKey('payroll_burden_multiplier');
    });

    it('snapshot reflects project type scope', function () {
        $estimate = Estimate::factory()->create([
            'project_type' => 'exterior',
        ]);

        expect($estimate->settings_snapshot['scope'])->toEqual('exterior');
    });
});

describe('Cost Breakdown Export', function () {
    it('can export CSV', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('estimates.breakdown.csv', $estimate));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('can export PDF', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('estimates.breakdown.pdf', $estimate));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    });
});

describe('Package-Specific Breakdown', function () {
    it('uses signed package for breakdown when available', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Create two areas
        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
        ]);

        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
        ]);

        // Create two packages - Good (recommended) and Better (signed)
        $goodPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Good Package',
            'tier' => 'basic',
            'is_recommended' => true,
            'is_selected' => false,
            'signed_at' => null,
            'total' => 1000.00,
        ]);

        $betterPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Better Package',
            'tier' => 'standard',
            'is_recommended' => false,
            'is_selected' => false,
            'signed_at' => now(),
            'total' => 2000.00,
        ]);

        // Good package includes area1 only
        EstimatePackageItem::factory()->create([
            'package_id' => $goodPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area1->id,
            'is_included' => true,
        ]);

        // Better package includes area2 only
        EstimatePackageItem::factory()->create([
            'package_id' => $betterPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area2->id,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should use signed package (Better)
        expect($breakdown['package'])->not->toBeNull();
        expect($breakdown['package']['tier'])->toBe('standard');
        expect($breakdown['package']['is_signed'])->toBeTrue();

        // Should only include area2 (Kitchen) from signed package
        expect($breakdown['areas'])->toHaveCount(1);
        expect($breakdown['areas'][0]['name'])->toBe('Kitchen');
    });

    it('uses selected package when no signed package exists', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
        ]);

        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
        ]);

        // Create two packages - Good (recommended) and Better (selected, not signed)
        $goodPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'basic',
            'is_recommended' => true,
            'is_selected' => false,
            'signed_at' => null,
            'total' => 1000.00,
        ]);

        $betterPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'standard',
            'is_recommended' => false,
            'is_selected' => true,
            'signed_at' => null,
            'total' => 2000.00,
        ]);

        // Good package includes area1
        EstimatePackageItem::factory()->create([
            'package_id' => $goodPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area1->id,
            'is_included' => true,
        ]);

        // Better package includes area2
        EstimatePackageItem::factory()->create([
            'package_id' => $betterPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area2->id,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should use selected package (Better)
        expect($breakdown['package']['tier'])->toBe('standard');
        expect($breakdown['package']['is_selected'])->toBeTrue();

        // Should only include area2
        expect($breakdown['areas'])->toHaveCount(1);
        expect($breakdown['areas'][0]['name'])->toBe('Kitchen');
    });

    it('uses recommended package as fallback', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
        ]);

        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
        ]);

        // Create two packages - neither signed nor selected
        $goodPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'basic',
            'is_recommended' => true, // This one is recommended
            'is_selected' => false,
            'signed_at' => null,
            'total' => 1000.00,
        ]);

        $betterPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'standard',
            'is_recommended' => false,
            'is_selected' => false,
            'signed_at' => null,
            'total' => 2000.00,
        ]);

        // Good package includes area1
        EstimatePackageItem::factory()->create([
            'package_id' => $goodPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area1->id,
            'is_included' => true,
        ]);

        // Better package includes area2
        EstimatePackageItem::factory()->create([
            'package_id' => $betterPackage->id,
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area2->id,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should use recommended package (Good)
        expect($breakdown['package']['tier'])->toBe('basic');
        expect($breakdown['package']['is_recommended'])->toBeTrue();

        // Should only include area1
        expect($breakdown['areas'])->toHaveCount(1);
        expect($breakdown['areas'][0]['name'])->toBe('Living Room');
    });

    it('falls back to legacy breakdown when no packages exist', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Create areas without any packages
        EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
        ]);

        EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should have no package info
        expect($breakdown['package'])->toBeNull();

        // Should include all areas (legacy behavior)
        expect($breakdown['areas'])->toHaveCount(2);
    });

    it('only includes items from the active package', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        // Create line items with explicit quantity/unit_price (model calculates total on save)
        $item1 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'description' => 'House Cleaning (Good)',
            'quantity' => 1,
            'unit_price' => 200.00,
        ]);

        $item2 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'description' => 'House Cleaning (Better)',
            'quantity' => 1,
            'unit_price' => 400.00,
        ]);

        // Create two packages
        $goodPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'basic',
            'is_recommended' => false,
            'is_selected' => false,
            'signed_at' => now(),
            'total' => 1200.00,
        ]);

        $betterPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'standard',
            'is_recommended' => true,
            'is_selected' => false,
            'signed_at' => null,
            'total' => 2400.00,
        ]);

        // Good package (signed) includes item1
        EstimatePackageItem::factory()->create([
            'package_id' => $goodPackage->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item1->id,
            'is_included' => true,
        ]);

        // Better package includes item2
        EstimatePackageItem::factory()->create([
            'package_id' => $betterPackage->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => true,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        // Should use signed package (Good)
        expect($breakdown['package']['tier'])->toBe('basic');

        // Should only include item1 (House Cleaning Good at $200)
        expect($breakdown['items'])->toHaveCount(1);
        expect($breakdown['items'][0]['description'])->toBe('House Cleaning (Good)');
        expect($breakdown['items_total'])->toBe(200.00);
    });

    it('displays package switcher in UI when package exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Better Package',
            'tier' => 'standard',
            'is_recommended' => true,
            'signed_at' => now(),
            'total' => 2500.00,
            'warranty_years' => 3,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSee('Better Package')
            ->assertSee('Signed')
            ->assertSee('3-Year Warranty');
    });

    it('includes warranty info in package breakdown', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'premium',
            'is_recommended' => true,
            'total' => 3000.00,
            'warranty_price' => 150.00,
            'warranty_years' => 5,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['package']['warranty_years'])->toBe(5);
        expect($breakdown['package']['warranty_price'])->toBe(150.00);
        expect($breakdown['cogs']['warranty_price'])->toBe(150.00);
    });
});

describe('Addon Labor Tracking', function () {
    it('populates labor_hours from template when addon is created', function () {
        // Create a template with explicit labor tracking
        $template = NestedAreaTemplate::factory()->create([
            'name' => 'Small Closet',
            'fixed_price' => 95.00,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        // Create addon (should auto-populate labor tracking from template)
        $addon = EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 1,
            'unit_price' => 95.00,
        ]);

        $addon->refresh();

        expect($addon->labor_hours)->toBe('1.50');
        expect($addon->material_cost)->toBe('12.00');
        expect((float) $addon->labor_cost)->toBeGreaterThan(0);
    });

    it('multiplies labor tracking by quantity', function () {
        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 95.00,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        $addon = EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 3,
            'unit_price' => 95.00,
        ]);

        $addon->refresh();

        // 1.5 hours × 3 = 4.5 hours
        expect($addon->labor_hours)->toBe('4.50');
        // $12 × 3 = $36
        expect($addon->material_cost)->toBe('36.00');
    });

    it('calculates labor cost using effective hourly rate', function () {
        // Set up company settings with known values
        $settings = CompanySetting::getInstance();
        $settings->interior_hourly_wage = 21.00;
        $settings->payroll_burden_multiplier = 1.35;
        $settings->save();
        CompanySetting::clearInstanceCache();

        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 95.00,
            'estimated_hours' => 2.0,
            'material_cost' => 10.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        $addon = EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 1,
            'unit_price' => 95.00,
        ]);

        $addon->refresh();

        // Effective rate = $21 × 1.35 = $28.35
        // Labor cost = 2 hours × $28.35 = $56.70
        expect($addon->labor_hours)->toBe('2.00');
        expect((float) $addon->labor_cost)->toBe(56.70);
    });

    it('includes addon labor hours in breakdown totals', function () {
        $settings = CompanySetting::getInstance();
        $settings->interior_hourly_wage = 21.00;
        $settings->payroll_burden_multiplier = 1.35;
        $settings->save();
        CompanySetting::clearInstanceCache();

        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 95.00,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => false,
        ]);

        // Create 2 closet addons
        EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 2, // 2 × 1.5 = 3 hours
            'unit_price' => 95.00,
        ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->calculateBreakdown($estimate);

        // Should include addon labor hours in totals
        expect($breakdown['totals']['addon_labor_hours'])->toBe(3.00);
        expect($breakdown['cogs']['addon_labor_hours'])->toBe(3.00);
        expect($breakdown['cogs']['addon_labor_cost'])->toBeGreaterThan(0);
        expect($breakdown['cogs']['addon_material_cost'])->toBe(24.00); // 12 × 2
    });

    it('includes addon details in area breakdown', function () {
        $template = NestedAreaTemplate::factory()->create([
            'name' => 'Walk-in Closet',
            'fixed_price' => 165.00,
            'estimated_hours' => 2.5,
            'material_cost' => 18.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => false,
        ]);

        EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 1,
            'unit_price' => 165.00,
        ]);

        $service = new EstimateCostBreakdownService;
        $areaBreakdown = $service->calculateAreaBreakdown($area);

        expect($areaBreakdown['detail']['addons'])->toHaveCount(1);
        expect($areaBreakdown['detail']['addons'][0]['name'])->toBe('Walk-in Closet');
        expect($areaBreakdown['detail']['addons'][0]['labor_hours'])->toBe(2.50);
        expect($areaBreakdown['detail']['addons'][0]['material_cost'])->toBe(18.00);
    });

    it('handles templates without labor tracking gracefully', function () {
        // Create a template without estimated_hours or material_cost
        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 100.00,
            'estimated_hours' => null,
            'material_cost' => null,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
        ]);

        $addon = EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 1,
            'unit_price' => 100.00,
        ]);

        $addon->refresh();

        // Should remain null (not populated)
        expect($addon->labor_hours)->toBeNull();
        expect($addon->material_cost)->toBeNull();
        expect($addon->labor_cost)->toBeNull();
    });

    it('adds addon labor hours to total hours in breakdown', function () {
        $settings = CompanySetting::getInstance();
        $settings->interior_hourly_wage = 21.00;
        $settings->interior_cleanup_hours_ratio = 1000.0; // Large ratio = minimal cleanup
        $settings->payroll_burden_multiplier = 1.35;
        $settings->save();
        CompanySetting::clearInstanceCache();

        $template = NestedAreaTemplate::factory()->create([
            'fixed_price' => 95.00,
            'estimated_hours' => 2.0,
            'material_cost' => 10.00,
        ]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => false,
        ]);

        // Add surface with 10 hours of labor
        $category = ProductionRateCategory::factory()->create(['name' => 'Walls']);
        $rate = ProductionRate::factory()->forCategory($category)->create();
        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'is_included' => true,
            'labor_hours' => 10.0,
            'labor_cost' => 283.50, // 10 × 28.35
        ]);

        // Add addon with 2 hours of labor
        EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'quantity' => 1,
            'unit_price' => 95.00,
        ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->calculateBreakdown($estimate);

        // Total hours = surface labor (10) + cleanup (~0) + addon (2) ≈ 12
        expect($breakdown['totals']['labor_hours'])->toBe(10.00);
        expect($breakdown['totals']['addon_labor_hours'])->toBe(2.00);
        // Total includes cleanup hours (small amount due to ratio)
        expect($breakdown['totals']['total_hours'])->toBeGreaterThanOrEqual(12.00);
        expect($breakdown['totals']['total_hours'])->toBeLessThan(13.00);
    });
});

describe('Dynamic Allocations', function () {
    it('calculates percent-based allocations correctly', function () {
        $settings = CompanySetting::getInstance();

        // Create percent-based allocations
        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Marketing',
            'slug' => 'marketing',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 8.00,
            'is_active' => true,
            'is_system' => true,
            'is_informational' => false,
        ]);

        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Sales',
            'slug' => 'sales',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 7.00,
            'is_active' => true,
            'is_system' => true,
            'is_informational' => false,
        ]);

        $result = $settings->calculateAllocations(1000.00, 10);

        // Marketing: 8% of $1000 = $80
        // Sales: 7% of $1000 = $70
        expect($result['total'])->toBe(150.00);
        expect($result['details'])->toHaveCount(2);

        // Find allocations by slug regardless of order
        $marketing = collect($result['details'])->firstWhere('slug', 'marketing');
        $sales = collect($result['details'])->firstWhere('slug', 'sales');
        expect($marketing['amount'])->toBe(80.00);
        expect($sales['amount'])->toBe(70.00);
    });

    it('calculates fixed-per-job allocations correctly', function () {
        $settings = CompanySetting::getInstance();

        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Growth Loan',
            'slug' => 'growth_loan',
            'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
            'value' => 150.00,
            'is_active' => true,
            'is_system' => false,
            'is_informational' => false,
        ]);

        $result = $settings->calculateAllocations(2000.00, 20);

        // Fixed per job: $150 regardless of price or hours
        expect($result['total'])->toBe(150.00);
        expect($result['details'][0]['amount'])->toBe(150.00);
    });

    it('calculates per-labor-hour allocations correctly', function () {
        $settings = CompanySetting::getInstance();

        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Equipment Fund',
            'slug' => 'equipment_fund',
            'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
            'value' => 5.00,
            'is_active' => true,
            'is_system' => false,
            'is_informational' => false,
        ]);

        $result = $settings->calculateAllocations(1000.00, 20);

        // Per labor hour: $5 × 20 hours = $100
        expect($result['total'])->toBe(100.00);
        expect($result['details'][0]['amount'])->toBe(100.00);
    });

    it('skips inactive allocations', function () {
        $settings = CompanySetting::getInstance();

        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Active',
            'slug' => 'active',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 10.00,
            'is_active' => true,
            'is_informational' => false,
        ]);

        CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 15.00,
            'is_active' => false,
            'is_informational' => false,
        ]);

        $result = $settings->calculateAllocations(1000.00, 0);

        // Only active allocation should be included
        expect($result['total'])->toBe(100.00); // 10% of $1000
        expect($result['details'])->toHaveCount(1);
    });

    it('prevents deletion of system allocations', function () {
        $settings = CompanySetting::getInstance();

        $allocation = CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'System Allocation',
            'slug' => 'system_allocation',
            'is_system' => true,
        ]);

        expect(fn () => $allocation->delete())
            ->toThrow(RuntimeException::class, 'System allocations cannot be deleted.');
    });

    it('allows deletion of custom allocations', function () {
        $settings = CompanySetting::getInstance();

        $allocation = CompanyAllocation::factory()->forCompanySetting($settings)->create([
            'name' => 'Custom Allocation',
            'slug' => 'custom_allocation',
            'is_system' => false,
        ]);

        $id = $allocation->id;
        $allocation->delete();

        expect(CompanyAllocation::find($id))->toBeNull();
    });

    it('uses dynamic allocations in breakdown when available', function () {
        $settings = CompanySetting::getInstance();
        $settings->ensureSystemAllocations();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => true,
            'fixed_price_amount' => 500.00,
        ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->calculateBreakdown($estimate);

        // Should have dynamic allocations with items array
        expect($breakdown['allocations'])->toHaveKey('items');
        expect($breakdown['allocations']['items'])->toBeArray();
        expect($breakdown['allocations']['total'])->toBeGreaterThan(0);
    });

    it('falls back to legacy allocations when no dynamic allocations exist', function () {
        // Make sure no allocations exist
        CompanyAllocation::query()->delete();

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => true,
            'fixed_price_amount' => 500.00,
        ]);

        $service = new EstimateCostBreakdownService;
        $breakdown = $service->calculateBreakdown($estimate);

        // Should have legacy allocations with named keys
        expect($breakdown['allocations'])->toHaveKey('marketing');
        expect($breakdown['allocations'])->toHaveKey('sales');
        expect($breakdown['allocations'])->toHaveKey('production_management');
        expect($breakdown['allocations']['total'])->toBeGreaterThan(0);
    });

    it('ensures system allocations are created correctly', function () {
        $settings = CompanySetting::getInstance();
        $settings->marketing_percent = 8.00;
        $settings->sales_percent = 7.00;
        $settings->production_management_percent = 7.00;
        $settings->overhead_percent = 12.00;
        $settings->save();

        $settings->ensureSystemAllocations();

        $allocations = $settings->allocations()->get();

        expect($allocations)->toHaveCount(4);
        expect($allocations->where('slug', 'marketing')->first()->value)->toBe('8.0000');
        expect($allocations->where('slug', 'sales')->first()->value)->toBe('7.0000');
        expect($allocations->where('slug', 'production_management')->first()->value)->toBe('7.0000');
        expect($allocations->where('slug', 'general_overhead')->first()->is_informational)->toBeTrue();
    });
});

describe('Warranty Reserve Integration', function () {
    it('includes warranty reserve info in package breakdown', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'premium',
            'is_recommended' => true,
            'total' => 3000.00,
            'warranty_price' => 150.00,
            'warranty_years' => 5,
        ]);

        // Create a warranty reserve for this package
        $reserve = \App\Models\WarrantyReserve::factory()->active()->create([
            'estimate_id' => $estimate->id,
            'estimate_package_id' => $package->id,
            'warranty_years' => 5,
            'amount_reserved' => 150.00,
            'amount_claimed' => 25.00,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['warranty_reserve'])->not->toBeNull();
        expect($breakdown['warranty_reserve']['status'])->toBe('active');
        expect($breakdown['warranty_reserve']['warranty_years'])->toBe(5);
        expect($breakdown['warranty_reserve']['amount_reserved'])->toBe(150.00);
        expect($breakdown['warranty_reserve']['amount_claimed'])->toBe(25.00);
        expect($breakdown['warranty_reserve']['remaining_balance'])->toBe(125.00);
        expect($breakdown['warranty_reserve']['claim_percentage'])->toBeGreaterThan(0);
    });

    it('returns not_created status when package has warranty but no reserve', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'premium',
            'is_recommended' => true,
            'total' => 3000.00,
            'warranty_price' => 150.00,
            'warranty_years' => 3,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['warranty_reserve'])->not->toBeNull();
        expect($breakdown['warranty_reserve']['status'])->toBe('not_created');
        expect($breakdown['warranty_reserve']['warranty_years'])->toBe(3);
        expect($breakdown['warranty_reserve']['expected_reserve'])->toBe(150.00);
    });

    it('returns null warranty reserve when package has no warranty', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'basic',
            'is_recommended' => true,
            'total' => 1500.00,
            'warranty_price' => 0,
            'warranty_years' => 0,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['warranty_reserve'])->toBeNull();
    });

    it('returns null warranty reserve for legacy estimates', function () {
        $service = new EstimateCostBreakdownService;

        // Legacy estimate without packages
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        EstimateArea::factory()->forEstimate($estimate)->create([
            'scope_type' => 'interior',
            'is_fixed_price' => true,
            'fixed_price_amount' => 500.00,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['package'])->toBeNull();
        expect($breakdown['warranty_reserve'])->toBeNull();
    });

    it('shows warranty claims in reserve info', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'premium',
            'is_recommended' => true,
            'total' => 3000.00,
            'warranty_price' => 200.00,
            'warranty_years' => 5,
        ]);

        // Create warranty reserve with some claims
        $reserve = \App\Models\WarrantyReserve::factory()->active()->create([
            'estimate_id' => $estimate->id,
            'estimate_package_id' => $package->id,
            'warranty_years' => 5,
            'amount_reserved' => 200.00,
            'amount_claimed' => 80.00,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['warranty_reserve']['remaining_balance'])->toBe(120.00);
        expect($breakdown['warranty_reserve']['claim_percentage'])->toBe(40.0);
        expect($breakdown['warranty_reserve']['has_available_balance'])->toBeTrue();
    });

    it('shows fully claimed warranty status', function () {
        $service = new EstimateCostBreakdownService;

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $package = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'tier' => 'premium',
            'is_recommended' => true,
            'total' => 3000.00,
            'warranty_price' => 150.00,
            'warranty_years' => 3,
        ]);

        // Create fully claimed warranty
        $reserve = \App\Models\WarrantyReserve::factory()->create([
            'estimate_id' => $estimate->id,
            'estimate_package_id' => $package->id,
            'warranty_years' => 3,
            'amount_reserved' => 150.00,
            'amount_claimed' => 150.00,
            'status' => \App\Models\WarrantyReserve::STATUS_FULLY_CLAIMED,
        ]);

        $breakdown = $service->calculateBreakdown($estimate);

        expect($breakdown['warranty_reserve']['status'])->toBe('fully_claimed');
        expect($breakdown['warranty_reserve']['remaining_balance'])->toBe(0.0);
        expect($breakdown['warranty_reserve']['has_available_balance'])->toBeFalse();
    });

    it('defaults selectedPackageId to active package on mount', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $good = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Good',
            'tier' => 'basic',
            'total' => 1000.00,
            'sort_order' => 1,
        ]);

        $better = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Better',
            'tier' => 'standard',
            'is_selected' => true,
            'total' => 2000.00,
            'sort_order' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSet('selectedPackageId', $better->id);
    });

    it('switches breakdown when selecting a different package', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $good = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Good',
            'tier' => 'basic',
            'total' => 500.00,
            'sort_order' => 1,
        ]);

        $best = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Best',
            'tier' => 'premium',
            'is_selected' => true,
            'total' => 1500.00,
            'sort_order' => 3,
        ]);

        // Start on Best (selected), then switch to Good
        $component = Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSet('selectedPackageId', $best->id);

        // Switch to Good - breakdown should change
        $component->call('selectPackage', $good->id)
            ->assertSet('selectedPackageId', $good->id);

        // Verify the breakdown is for the Good package
        $breakdown = $component->get('currentBreakdown');
        expect($breakdown['package']['id'])->toBe($good->id);
        expect($breakdown['package']['name'])->toBe('Good');
    });

    it('shows all packages in switcher bar', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Good',
            'tier' => 'basic',
            'total' => 1000.00,
            'sort_order' => 1,
        ]);

        EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Better',
            'tier' => 'standard',
            'is_recommended' => true,
            'total' => 2000.00,
            'sort_order' => 2,
        ]);

        EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Best',
            'tier' => 'premium',
            'is_selected' => true,
            'total' => 3000.00,
            'sort_order' => 3,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSee('Good')
            ->assertSee('Better')
            ->assertSee('Best')
            ->assertSee('$1,000.00')
            ->assertSee('$2,000.00')
            ->assertSee('$3,000.00')
            ->assertSee('Selected')
            ->assertSee('Recommended');
    });

    it('ignores selectPackage for packages from other estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $otherEstimate = Estimate::factory()->create(['project_type' => 'interior']);

        $myPackage = EstimatePackage::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Good',
            'tier' => 'basic',
            'is_recommended' => true,
            'total' => 1000.00,
            'sort_order' => 1,
        ]);

        $foreignPackage = EstimatePackage::factory()->create([
            'estimate_id' => $otherEstimate->id,
            'name' => 'Other',
            'tier' => 'basic',
            'total' => 9999.00,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.cost-breakdown', ['estimate' => $estimate])
            ->assertSet('selectedPackageId', $myPackage->id)
            ->call('selectPackage', $foreignPackage->id)
            ->assertSet('selectedPackageId', $myPackage->id); // Should not change
    });
});
