<?php

use App\Models\CompanySetting;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use Livewire\Livewire;

describe('Production Rates Index Calculator (PBP Formula)', function () {
    beforeEach(function () {
        // Set up deterministic company settings for PBP calculations
        CompanySetting::query()->delete();
        CompanySetting::create([
            'company_name' => 'Test Company',
            'interior_hourly_wage' => 21.00,
            'exterior_hourly_wage' => 27.00,
            'payroll_burden_multiplier' => 1.35,
            'interior_cleanup_labor_ratio' => 16,
            'exterior_cleanup_labor_ratio' => 20,
            'interior_sundries_per_labor_hour' => 3.30,
            'exterior_sundries_per_labor_hour' => 1.00,
            'profit_margin_percent' => 30,
            'overhead_percent' => 12,
            'interior_scope_markup' => 50,
            'exterior_scope_markup' => 41,
        ]);
    });

    it('can open the calculator modal with a rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Wall Painting Test',
            'production_rate' => 300,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->assertSet('showCalculatorModal', false)
            ->call('openCalculator', $rate->id)
            ->assertSet('showCalculatorModal', true)
            ->assertSet('calculatorRateId', $rate->id)
            ->assertSet('calculatorSqft', 500)
            ->assertSet('calculatorCoats', 2)
            ->assertSet('calculatorScopeType', 'interior'); // Should default to interior based on category

        expect($component->get('calculatorResult'))->not->toBeNull();
    });

    it('initializes calculator with default values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Wall Painting',
            'production_rate' => 300,
        ]);

        Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->assertSet('calculatorSqft', 500)
            ->assertSet('calculatorCoats', 2)
            ->assertSet('calculatorScopeType', 'interior');
    });

    it('calculates preview when opening calculator', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 300,
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id);

        $result = $component->get('calculatorResult');

        expect($result)->not->toBeNull()
            ->and($result['rate']->id)->toBe($rate->id)
            ->and($result['quantity'])->toBe(500.0)
            ->and($result['coats'])->toBe(2)
            ->and($result['scope_type'])->toBe('interior')
            ->and($result['error'])->toBeNull();
    });

    it('updates calculation when sqft changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 300,
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id);

        $initialResult = $component->get('calculatorResult');
        $initialTotal = $initialResult['with_margins'];

        // Change sqft to 1000
        $component->set('calculatorSqft', 1000);

        $newResult = $component->get('calculatorResult');

        expect($newResult['quantity'])->toBe(1000.0)
            ->and($newResult['with_margins'])->toBeGreaterThan($initialTotal);
    });

    it('updates calculation when coats changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 300,
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id);

        // Change from 2 coats to 3 coats
        $component->set('calculatorCoats', 3);

        $result = $component->get('calculatorResult');

        expect($result['coats'])->toBe(3)
            ->and($result['coat_multiplier'])->toBe(2.5);
    });

    it('updates calculation when scope type changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 300,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id);

        $interiorResult = $component->get('calculatorResult');

        // Change to exterior scope
        $component->set('calculatorScopeType', 'exterior');

        $exteriorResult = $component->get('calculatorResult');

        // Exterior has different rates: $27 vs $21 wage, different markups
        expect($exteriorResult['scope_type'])->toBe('exterior')
            ->and($exteriorResult['effective_hourly_rate'])->toBeGreaterThan($interiorResult['effective_hourly_rate']);
    });

    it('can close the calculator modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create();

        Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->assertSet('showCalculatorModal', true)
            ->call('closeCalculator')
            ->assertSet('showCalculatorModal', false)
            ->assertSet('calculatorRateId', null)
            ->assertSet('calculatorSqft', 500)
            ->assertSet('calculatorCoats', 2)
            ->assertSet('calculatorScopeType', 'interior')
            ->assertSet('calculatorResult', null);
    });

    it('displays rate name in calculator modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Custom Rate Name',
        ]);

        Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->assertSee('Custom Rate Name');
    });

    it('calculates labor hours with coat multiplier', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200, // 200 sqft/hr
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->set('calculatorSqft', 400)
            ->set('calculatorCoats', 2);

        $result = $component->get('calculatorResult');

        // 400 sqft / 200 sqft/hr = 2 hours × 1.8 coat multiplier = 3.6 hours
        expect($result['labor_hours'])->toBe(3.6);
    });

    it('calculates labor cost using scope-specific rates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->set('calculatorSqft', 400)
            ->set('calculatorCoats', 1)
            ->set('calculatorScopeType', 'interior');

        $result = $component->get('calculatorResult');

        // Labor hours: 400/200 = 2 hrs
        // Effective rate (interior): $21 × 1.35 burden = $28.35/hr
        // Labor cost: 2 × $28.35 = $56.70
        expect($result['labor_hours'])->toBe(2.0)
            ->and($result['effective_hourly_rate'])->toBe(28.35)
            ->and($result['labor_cost'])->toBe(56.70);
    });

    // Material cost is calculated from Paint Products, not ProductionRate
    // This test is removed as material_cost_per_unit no longer exists on ProductionRate

    it('calculates cleanup hours and cost', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->set('calculatorSqft', 400)
            ->set('calculatorCoats', 1)
            ->set('calculatorScopeType', 'interior');

        $result = $component->get('calculatorResult');

        // Labor hours: 2
        // Cleanup ratio (interior): 16:1
        // Cleanup hours: ~2/16 = ~0.125 (may be rounded)
        // Cleanup cost: cleanup_hours × $28.35 > 0
        expect($result['cleanup_hours'])->toBeGreaterThan(0.1)
            ->and($result['cleanup_hours'])->toBeLessThan(0.2)
            ->and($result['cleanup_cost'])->toBeGreaterThan(0);
    });

    it('calculates sundries cost', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->set('calculatorSqft', 400)
            ->set('calculatorCoats', 1)
            ->set('calculatorScopeType', 'interior');

        $result = $component->get('calculatorResult');

        // Labor hours: 2
        // Sundries rate (interior): $3.30/hr
        // Sundries cost: 2 × $3.30 = $6.60
        expect($result['sundries_cost'])->toBe(6.60);
    });

    it('calculates with margins for scope', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->set('calculatorSqft', 400)
            ->set('calculatorCoats', 1)
            ->set('calculatorScopeType', 'interior');

        $result = $component->get('calculatorResult');

        // Base total should be: labor + cleanup + sundries + material
        // With margins should be: base × profit (1.30) × overhead (1.12) × interior markup (1.50)
        expect($result['base_total'])->toBeGreaterThan(0)
            ->and($result['with_margins'])->toBeGreaterThan($result['base_total']);
    });

    it('shows test button for each rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Test Rate For Button',
        ]);

        Livewire::actingAs($user)
            ->test('production-rates.index')
            ->assertSee('Test Rate For Button')
            ->assertSee('Test'); // The test button text
    });

    it('resets calculator state when closing and reopening', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate1 = ProductionRate::factory()->forCategory($category)->create(['name' => 'Rate One']);
        $rate2 = ProductionRate::factory()->forCategory($category)->create(['name' => 'Rate Two']);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate1->id)
            ->set('calculatorSqft', 1000)
            ->set('calculatorCoats', 3)
            ->set('calculatorScopeType', 'exterior')
            ->call('closeCalculator')
            ->call('openCalculator', $rate2->id);

        // Should have reset to defaults
        expect($component->get('calculatorSqft'))->toBe(500.0)
            ->and($component->get('calculatorCoats'))->toBe(2)
            ->and($component->get('calculatorScopeType'))->toBe('interior')
            ->and($component->get('calculatorRateId'))->toBe($rate2->id);
    });

    it('defaults scope type based on rate category', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $exteriorCategory = ProductionRateCategory::factory()->exterior()->create();
        $exteriorRate = ProductionRate::factory()->forCategory($exteriorCategory)->create();

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $exteriorRate->id);

        expect($component->get('calculatorScopeType'))->toBe('exterior');
    });

    it('displays all PBP breakdown fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'production_rate' => 200,
        ]);

        $component = Livewire::actingAs($user)
            ->test('production-rates.index')
            ->call('openCalculator', $rate->id)
            ->assertSee('Labor Hours')
            ->assertSee('Effective Rate')
            ->assertSee('Labor Cost')
            ->assertSee('Cleanup Hours')
            ->assertSee('Cleanup Cost')
            ->assertSee('Sundries Cost')
            ->assertSee('Base Total')
            ->assertSee('With Margins');
    });
});
