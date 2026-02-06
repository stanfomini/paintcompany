<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
});

function createEstimateWithPaintProduct(float $subtotal = 5000.00): array
{
    $estimate = Estimate::factory()->create([
        'subtotal' => $subtotal,
        'total' => $subtotal,
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Living Room',
        'scope_type' => 'interior',
    ]);

    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    $product = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Duration',
        'price_per_gallon' => 65.00,
        'coverage_sqft_brush_roll' => 400,
        'sheen' => 'satin',
    ]);

    $surface = EstimateAreaSurface::factory()->wall()->create([
        'area_id' => $area->id,
        'paint_product_id' => $product->id,
        'quantity' => 400,
        'coats' => 2,
        'paint_gallons' => 2,
        'labor_hours' => 4.0,
        'labor_cost' => 84.00,
        'material_cost' => 130.00,
        'total' => 214.00,
        'is_included' => true,
    ]);

    return compact('estimate', 'area', 'brand', 'product', 'surface');
}

describe('Actual Paint Cost Entry', function () {
    it('shows the materials profit table on financials tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct();

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials')
            ->assertSee('Materials')
            ->assertSee('Paint Profit')
            ->assertSee('Your Cost');
    });

    it('calculates profit when actual paint cost is entered', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct();

        $productId = (string) $data['product']->id;

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials')
            ->set("actualPaintCosts.{$productId}", 45.00);

        $materialsProfit = $component->get('materialsProfit');

        // Catalog: $65/gal × 2 gal = $130, Actual: $45/gal × 2 gal = $90, Profit = $40
        expect($materialsProfit['has_actuals'])->toBeTrue();
        expect($materialsProfit['items'][0]['actual_price'])->toBe(45.0);
        expect($materialsProfit['items'][0]['actual_total'])->toBe(90.0);
        expect($materialsProfit['items'][0]['profit'])->toBe(40.0);
    });

    it('shows no profit when no actual cost entered', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials');

        $materialsProfit = $component->get('materialsProfit');

        expect($materialsProfit['has_actuals'])->toBeFalse();
        expect($materialsProfit['items'][0]['actual_price'])->toBeNull();
        expect($materialsProfit['items'][0]['profit'])->toBeNull();
    });
});

describe('Individual Painters', function () {
    it('can add a painter with default wage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('addPainter');

        $painters = $component->get('painters');

        expect($painters)->toHaveCount(1);
        expect($painters[0]['name'])->toBe('');
        expect($painters[0]['hourly_rate'])->toBeGreaterThan(0);
        expect($painters[0]['hours'])->toBe(0);
    });

    it('can remove a painter', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('addPainter')
            ->call('addPainter')
            ->call('removePainter', 0);

        $painters = $component->get('painters');

        expect($painters)->toHaveCount(1);
    });

    it('calculates painter breakdown with pay and burden totals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $settings = CompanySetting::getInstance();
        $burdenMultiplier = (float) ($settings->payroll_burden_multiplier ?? 1.35);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('addPainter')
            ->set('painters.0.name', 'Mike')
            ->set('painters.0.hourly_rate', 25.00)
            ->set('painters.0.hours', 40)
            ->call('addPainter')
            ->set('painters.1.name', 'Dave')
            ->set('painters.1.hourly_rate', 22.00)
            ->set('painters.1.hours', 32);

        $breakdown = $component->get('paintersBreakdown');

        // Mike: $25 × 40 = $1000 base
        expect($breakdown['painters'][0]['base_pay'])->toBe(1000.00);

        // Dave: $22 × 32 = $704 base
        expect($breakdown['painters'][1]['base_pay'])->toBe(704.00);

        // Totals
        expect($breakdown['total_base_pay'])->toBe(1704.00);
        expect($breakdown['total_hours'])->toBe(72.0);

        // Burden on total wages: $1704 × 0.35 = $596.40
        expect($breakdown['total_burden_cost'])->toBe(round(1704 * ($burdenMultiplier - 1), 2));

        // Total labor cost: wages + burden
        $expectedLaborCost = round(1704 + 1704 * ($burdenMultiplier - 1), 2);
        expect($breakdown['total_labor_cost'])->toBe($expectedLaborCost);
    });
});

describe('Job Profit Summary', function () {
    it('calculates job price from company pays loaded + materials', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials');

        $profit = $component->get('profitSummary');
        $painterPay = $component->get('painterPayBreakdown');

        // Job price = company pays loaded + catalog paint + sundries + recycle
        $expectedJobPrice = $painterPay['company_pays_loaded']
            + $profit['paint_cost']
            + $profit['sundries_cost']
            + $profit['recycle_fee'];

        expect($profit['job_price'])->toBe(round($expectedJobPrice, 2));
    });

    it('has zero profit with no actuals entered', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials');

        $profit = $component->get('profitSummary');

        expect($profit['has_actuals'])->toBeFalse();
        // With no actuals: price = loaded labor + catalog materials
        // Costs = base pay + burden + catalog paint + sundries + recycle = loaded labor + catalog materials
        // Profit = ~0 (may have minor floating point variations)
        expect(abs($profit['gross_profit']))->toBeLessThan(0.1);
    });

    it('shows profit from paint markup when actuals entered', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);
        $productId = (string) $data['product']->id;

        // Enter actual paint cost lower than catalog ($45 vs $65/gal)
        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials')
            ->set("actualPaintCosts.{$productId}", 45.00);

        $profit = $component->get('profitSummary');

        // Paint markup: catalog $65 × 2 gal = $130 in price, actual $45 × 2 = $90 in costs
        // Profit = ~$40 (only paint difference since no painters entered)
        expect($profit['gross_profit'])->toBeGreaterThan(39.9)
            ->toBeLessThan(40.1);
    });

    it('shows profit from crew rate differential', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);

        $settings = CompanySetting::getInstance();
        $burdenMultiplier = (float) ($settings->payroll_burden_multiplier ?? 1.35);

        // Pay painter $20/hr, company loaded rate is higher
        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials')
            ->call('addPainter')
            ->set('painters.0.name', 'Mike')
            ->set('painters.0.hourly_rate', 20.00)
            ->set('painters.0.hours', 40);

        $profit = $component->get('profitSummary');
        $painterPay = $component->get('painterPayBreakdown');

        // Labor profit = company_pays_loaded - actual wages - actual burden
        $actualWages = 800.00;
        $actualBurden = round(800 * ($burdenMultiplier - 1), 2);
        $laborProfit = round($painterPay['company_pays_loaded'] - $actualWages - $actualBurden, 2);

        // Gross profit should equal labor profit (no paint actuals, so paint cancels out)
        expect($profit['gross_profit'])->toBe($laborProfit);
        expect($profit['crew_wages'])->toBe(800.00);
        expect($profit['burden_cost'])->toBe($actualBurden);
    });

    it('shows negative profit when crew costs exceed loaded rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);

        $settings = CompanySetting::getInstance();
        $companyRate = $settings->getHourlyWageForScope('interior');

        // Pay painter MORE than the loaded rate
        $expensiveRate = $companyRate * 2;
        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->call('setTab', 'financials')
            ->call('addPainter')
            ->set('painters.0.name', 'Mike')
            ->set('painters.0.hourly_rate', $expensiveRate)
            ->set('painters.0.hours', 40);

        $profit = $component->get('profitSummary');

        // Profit should be negative (paying crew more than what company pays loaded)
        expect($profit['gross_profit'])->toBeLessThan(0);
    });
});

describe('Saving Actuals and Snapshots', function () {
    it('saves actual paint costs and painters to estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct();
        $productId = (string) $data['product']->id;

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->set("actualPaintCosts.{$productId}", 42.00)
            ->call('addPainter')
            ->set('painters.0.name', 'Mike')
            ->set('painters.0.hourly_rate', 25.00)
            ->set('painters.0.hours', 40)
            ->call('saveActuals');

        $data['estimate']->refresh();

        expect($data['estimate']->actual_paint_costs)->toBeArray();
        expect((float) $data['estimate']->actual_paint_costs[$productId])->toBe(42.0);
        expect($data['estimate']->actual_painters)->toHaveCount(1);
        expect($data['estimate']->actual_painters[0]['name'])->toBe('Mike');
    });

    it('saves a profit snapshot with job price', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct(5000.00);
        $productId = (string) $data['product']->id;

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']])
            ->set("actualPaintCosts.{$productId}", 42.00)
            ->call('addPainter')
            ->set('painters.0.name', 'Mike')
            ->set('painters.0.hourly_rate', 25.00)
            ->set('painters.0.hours', 40)
            ->call('saveSnapshot');

        $data['estimate']->refresh();

        expect($data['estimate']->profit_snapshot)->toBeArray();
        expect($data['estimate']->profit_snapshot)->toHaveKey('captured_at');
        expect($data['estimate']->profit_snapshot)->toHaveKey('gross_profit');
        expect($data['estimate']->profit_snapshot)->toHaveKey('job_price');
        expect($data['estimate']->profit_snapshot)->toHaveKey('total_costs');
        expect($data['estimate']->profit_snapshot)->toHaveKey('painters');
        expect($data['estimate']->profit_snapshot)->toHaveKey('paint_costs');
    });

    it('loads existing actuals on mount', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $data = createEstimateWithPaintProduct();
        $productId = (string) $data['product']->id;

        // Pre-save actuals
        $data['estimate']->update([
            'actual_paint_costs' => [$productId => 42.00],
            'actual_painters' => [
                ['name' => 'Mike', 'hourly_rate' => 25.00, 'hours' => 40, 'notes' => ''],
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $data['estimate']]);

        expect($component->get('actualPaintCosts'))->toHaveKey($productId);
        expect((float) $component->get('actualPaintCosts')[$productId])->toBe(42.0);
        expect($component->get('painters'))->toHaveCount(1);
        expect($component->get('painters')[0]['name'])->toBe('Mike');
    });
});
