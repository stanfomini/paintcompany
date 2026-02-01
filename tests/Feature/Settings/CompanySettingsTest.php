<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->agentUser = User::factory()->agent()->create();
    $this->customerUser = User::factory()->customer()->create();
});

it('internal users can access company settings page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('company.edit'))
        ->assertSuccessful()
        ->assertSee('Company')
        ->assertSee('Company Information');
});

it('agent users cannot access company settings page', function () {
    $this->actingAs($this->agentUser)
        ->get(route('company.edit'))
        ->assertForbidden();
});

it('customer users cannot access company settings page', function () {
    $this->actingAs($this->customerUser)
        ->get(route('company.edit'))
        ->assertForbidden();
});

it('guests are redirected to login', function () {
    $this->get(route('company.edit'))
        ->assertRedirect(route('login'));
});

it('creates default settings on first access', function () {
    $this->assertDatabaseCount('company_settings', 0);

    $this->actingAs($this->internalUser)
        ->get(route('company.edit'))
        ->assertSuccessful();

    $this->assertDatabaseCount('company_settings', 1);
});

it('internal users can update company name', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Apex Painting Co.')
        ->call('updateCompanySettings')
        ->assertDispatched('company-settings-updated');

    $this->assertDatabaseHas('company_settings', [
        'company_name' => 'Apex Painting Co.',
    ]);
});

it('internal users can update contact information', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('company_email', 'contact@apex.com')
        ->set('company_phone', '555-123-4567')
        ->call('updateCompanySettings')
        ->assertDispatched('company-settings-updated');

    $this->assertDatabaseHas('company_settings', [
        'company_email' => 'contact@apex.com',
        'company_phone' => '555-123-4567',
    ]);
});

it('internal users can update address', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('address_line_1', '123 Main St')
        ->set('address_line_2', 'Suite 100')
        ->set('city', 'Austin')
        ->set('state', 'TX')
        ->set('zip_code', '78701')
        ->call('updateCompanySettings')
        ->assertDispatched('company-settings-updated');

    $this->assertDatabaseHas('company_settings', [
        'address_line_1' => '123 Main St',
        'address_line_2' => 'Suite 100',
        'city' => 'Austin',
        'state' => 'TX',
        'zip_code' => '78701',
    ]);
});

it('internal users can update defaults', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('default_tax_rate', '8.25')
        ->set('default_payment_terms_days', '14')
        ->set('estimate_validity_days', '45')
        ->call('updateCompanySettings')
        ->assertDispatched('company-settings-updated');

    $settings = CompanySetting::first();
    expect($settings->default_tax_rate)->toBe('8.25');
    expect($settings->default_payment_terms_days)->toBe(14);
    expect($settings->estimate_validity_days)->toBe(45);
});

it('validates company name is required', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', '')
        ->call('updateCompanySettings')
        ->assertHasErrors(['company_name' => 'required']);
});

it('validates email format', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('company_email', 'invalid-email')
        ->call('updateCompanySettings')
        ->assertHasErrors(['company_email' => 'email']);
});

it('validates tax rate bounds', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('default_tax_rate', '150')
        ->call('updateCompanySettings')
        ->assertHasErrors(['default_tax_rate' => 'max']);
});

it('validates payment terms bounds', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->set('company_name', 'Test Company')
        ->set('default_payment_terms_days', '500')
        ->call('updateCompanySettings')
        ->assertHasErrors(['default_payment_terms_days' => 'max']);
});

it('loads existing settings on mount', function () {
    CompanySetting::create([
        'company_name' => 'Loaded Company',
        'company_email' => 'loaded@test.com',
        'default_tax_rate' => 7.5,
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.company')
        ->assertSet('company_name', 'Loaded Company')
        ->assertSet('company_email', 'loaded@test.com')
        ->assertSet('default_tax_rate', '7.50');
});

it('shows company link in settings nav for internal users', function () {
    $this->actingAs($this->internalUser)
        ->get(route('profile.edit'))
        ->assertSee('Company')
        ->assertSee(route('company.edit'));
});

it('does not show company link in settings nav for agents', function () {
    $this->actingAs($this->agentUser)
        ->get(route('profile.edit'))
        ->assertDontSee(route('company.edit'));
});

it('does not show company link in settings nav for customers', function () {
    $this->actingAs($this->customerUser)
        ->get(route('profile.edit'))
        ->assertDontSee(route('company.edit'));
});

describe('CompanySetting model', function () {
    it('returns singleton instance', function () {
        $first = CompanySetting::getInstance();
        $second = CompanySetting::getInstance();

        expect($first->id)->toBe($second->id);
        $this->assertDatabaseCount('company_settings', 1);
    });

    it('creates default record with app name', function () {
        config(['app.name' => 'Test App Name']);
        $settings = CompanySetting::getInstance();

        expect($settings->company_name)->toBe('Test App Name');
    });

    it('generates full address correctly', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '78701',
        ]);

        expect($settings->full_address)->toContain('123 Main St');
        expect($settings->full_address)->toContain('Austin');
        expect($settings->full_address)->toContain('TX 78701');
    });

    it('returns null for empty address', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        expect($settings->full_address)->toBeNull();
    });

    it('calculates effective hourly rate', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
        ]);

        expect($settings->effective_hourly_rate)->toBe(33.75);
    });

    it('uses default values for effective hourly rate when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        // Default: 25.00 * 1.35 = 33.75
        expect($settings->effective_hourly_rate)->toBe(33.75);
    });

    it('gets paint price from catalog', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'paint_catalog' => [
                'sherwin_williams' => [
                    'duration' => 75.00,
                    'emerald' => 95.00,
                ],
            ],
        ]);

        expect($settings->getPaintPrice('sherwin_williams', 'duration'))->toBe(75.00);
        expect($settings->getPaintPrice('sherwin_williams', 'emerald'))->toBe(95.00);
    });

    it('normalizes brand and type names for paint lookup', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'paint_catalog' => [
                'benjamin_moore' => [
                    'regal_select' => 70.00,
                ],
            ],
        ]);

        expect($settings->getPaintPrice('benjamin-moore', 'regal-select'))->toBe(70.00);
        expect($settings->getPaintPrice('Benjamin Moore', 'Regal Select'))->toBe(70.00);
    });

    it('returns null for unknown paint', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        expect($settings->getPaintPrice('unknown_brand', 'unknown_type'))->toBeNull();
    });

    it('gets trim width by type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'trim_widths' => [
                'baseboard' => 4.0,
                'crown_molding' => 6.0,
            ],
        ]);

        expect($settings->getTrimWidth('baseboard'))->toBe(4.0);
        expect($settings->getTrimWidth('crown_molding'))->toBe(6.0);
    });

    it('normalizes trim type names for lookup', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'trim_widths' => [
                'crown_molding' => 6.0,
            ],
        ]);

        expect($settings->getTrimWidth('crown-molding'))->toBe(6.0);
        expect($settings->getTrimWidth('Crown Molding'))->toBe(6.0);
    });

    it('returns null for unknown trim type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        expect($settings->getTrimWidth('unknown_trim'))->toBeNull();
    });

    it('calculates markup price', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'markup_percent' => 45.00,
        ]);

        // 100 * 1.45 = 145
        expect($settings->calculateMarkupPrice(100.00))->toBe(145.00);
    });

    it('uses default markup percent when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        // 100 * 1.45 = 145 (default 45%)
        expect($settings->calculateMarkupPrice(100.00))->toBe(145.00);
    });

    it('calculates price with profit margin', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'net_profit_margin_percent' => 30.00,
        ]);

        // 70 / (1 - 0.30) = 70 / 0.70 = 100
        expect($settings->calculateWithProfitMargin(70.00))->toBe(100.00);
    });

    it('uses default profit margin when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        // 70 / 0.70 = 100 (default 30%)
        expect($settings->calculateWithProfitMargin(70.00))->toBe(100.00);
    });

    it('handles edge case of 100% profit margin', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'net_profit_margin_percent' => 100.00,
        ]);

        // Should return original cost to prevent division by zero
        expect($settings->calculateWithProfitMargin(100.00))->toBe(100.00);
    });

    it('returns warranty options', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'warranty_options' => [
                ['years' => 2, 'name' => 'Custom', 'description' => 'Custom warranty', 'price_multiplier' => 1.03],
            ],
        ]);

        $options = $settings->getWarrantyOptions();
        expect($options)->toHaveCount(1);
        expect($options[0]['years'])->toBe(2);
        expect($options[0]['name'])->toBe('Custom');
    });

    it('returns default warranty options when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        $options = $settings->getWarrantyOptions();
        expect($options)->toHaveCount(3);
        expect($options[0]['years'])->toBe(1);
        expect($options[1]['years'])->toBe(3);
        expect($options[2]['years'])->toBe(5);
    });

    it('provides default paint catalog', function () {
        $catalog = CompanySetting::getDefaultPaintCatalog();

        expect($catalog)->toHaveKey('sherwin_williams');
        expect($catalog)->toHaveKey('benjamin_moore');
        expect($catalog['sherwin_williams'])->toHaveKey('duration');
    });

    it('provides default trim widths', function () {
        $widths = CompanySetting::getDefaultTrimWidths();

        expect($widths)->toHaveKey('baseboard');
        expect($widths)->toHaveKey('crown_molding');
        expect($widths['baseboard'])->toBe(4.0);
    });

    it('provides default warranty options', function () {
        $options = CompanySetting::getDefaultWarrantyOptions();

        expect($options)->toHaveCount(3);
        expect($options[0])->toHaveKey('years');
        expect($options[0])->toHaveKey('name');
        expect($options[0])->toHaveKey('description');
        expect($options[0])->toHaveKey('price_multiplier');
    });

    it('casts all pricing fields correctly', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
            'net_profit_margin_percent' => 30.00,
            'overhead_percent' => 12.00,
            'markup_percent' => 45.00,
            'default_deposit_percent' => 30.00,
            'default_sales_discount_percent' => 10.00,
            'material_tax_rate' => 7.50,
            'default_interior_sqft_rate' => 4.5000,
            'default_exterior_sqft_rate' => 3.5000,
            'cleanup_hours_ratio' => 16.00,
            'sundries_cost_per_prep_hour' => 3.30,
            'spray_paint_coverage_multiplier' => 2.00,
            'brush_roll_coverage_multiplier' => 1.30,
            'recycle_fee_per_gallon' => 0.54,
            'wood_replacement_minimum' => 300.00,
        ]);

        $settings->refresh();

        expect($settings->base_hourly_wage)->toBe('25.00');
        expect($settings->payroll_burden_multiplier)->toBe('1.35');
        expect($settings->net_profit_margin_percent)->toBe('30.00');
        expect($settings->default_interior_sqft_rate)->toBe('4.5000');
        expect($settings->default_exterior_sqft_rate)->toBe('3.5000');
    });

    it('casts json fields to arrays', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'paint_catalog' => ['test' => ['value' => 10]],
            'trim_widths' => ['baseboard' => 4.0],
            'warranty_options' => [['years' => 1, 'name' => 'Test']],
        ]);

        $settings->refresh();

        expect($settings->paint_catalog)->toBeArray();
        expect($settings->trim_widths)->toBeArray();
        expect($settings->warranty_options)->toBeArray();
    });

    it('calculates with all margins applied sequentially', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'net_profit_margin_percent' => 30.00,
            'overhead_percent' => 10.00,
            'markup_percent' => 20.00,
        ]);

        // Base cost: 100
        // With 30% profit: 100 * 1.30 = 130
        // With 10% overhead: 130 * 1.10 = 143
        // With 20% markup: 143 * 1.20 = 171.60
        expect($settings->calculateWithMargins(100.00))->toBe(171.60);
    });

    it('uses default margins when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        // Base cost: 100
        // With 30% profit: 100 * 1.30 = 130
        // With 12% overhead: 130 * 1.12 = 145.60
        // With 45% markup: 145.60 * 1.45 = 211.12
        expect($settings->calculateWithMargins(100.00))->toBe(211.12);
    });

    it('calculates labor cost from hours', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.40,
        ]);

        // Effective rate: 25 * 1.40 = 35
        // Labor cost: 35 * 8 hours = 280
        expect($settings->calculateLaborCost(8.00))->toBe(280.00);
    });

    it('calculates price from production rate with full formula', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'base_hourly_wage' => 20.00,
            'payroll_burden_multiplier' => 1.50,
            'net_profit_margin_percent' => 20.00,
            'overhead_percent' => 10.00,
            'markup_percent' => 15.00,
        ]);

        // Effective hourly rate: 20 * 1.50 = 30
        // Hours: 300 sqft / 150 sqft/hour = 2 hours
        // Labor cost: 2 * 30 * 2 coats = 120
        // Material cost: 25 * 2 coats = 50
        // Base cost: 120 + 50 = 170
        // With 20% profit: 170 * 1.20 = 204
        // With 10% overhead: 204 * 1.10 = 224.40
        // With 15% markup: 224.40 * 1.15 = 258.06
        expect($settings->calculateFromProductionRate(
            quantity: 300.00,
            productionRate: 150.00,
            materialCost: 25.00,
            coats: 2
        ))->toBe(258.06);
    });

    it('handles zero production rate gracefully', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
        ]);

        // Should return just the material cost when production rate is zero
        expect($settings->calculateFromProductionRate(
            quantity: 300.00,
            productionRate: 0,
            materialCost: 50.00,
            coats: 1
        ))->toBe(50.00);
    });

    it('calculates single coat by default', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.00, // No burden for simple math
            'net_profit_margin_percent' => 0,
            'overhead_percent' => 0,
            'markup_percent' => 0,
        ]);

        // Hours: 100 sqft / 100 sqft/hour = 1 hour
        // Labor cost: 1 * 25 * 1 coat = 25
        // Material cost: 10 * 1 coat = 10
        // Base cost: 25 + 10 = 35
        // No margins = 35
        expect($settings->calculateFromProductionRate(
            quantity: 100.00,
            productionRate: 100.00,
            materialCost: 10.00
        ))->toBe(35.00);
    });
});
