<?php

use App\Models\CompanySetting;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('CompanySetting pricing calculations', function () {
    it('calculates effective hourly rate by multiplying wage and burden', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
        ]);

        // Effective rate = 25 * 1.35 = 33.75
        expect($settings->effective_hourly_rate)->toBe(33.75);
    });

    it('uses default values when wage and multiplier not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        // Default: 25 * 1.35 = 33.75
        expect($settings->effective_hourly_rate)->toBe(33.75);
    });

    it('calculates markup price by applying markup percent', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'markup_percent' => 45.00,
        ]);

        // Cost of 100 with 45% markup = 100 * 1.45 = 145
        $price = $settings->calculateMarkupPrice(100.00);

        expect($price)->toBe(145.00);
    });

    it('uses default markup percent when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        // Default markup is 45%
        // Cost of 200 with 45% markup = 200 * 1.45 = 290
        $price = $settings->calculateMarkupPrice(200.00);

        expect($price)->toBe(290.00);
    });

    it('calculates price with profit margin using inverse calculation', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 30.00,
        ]);

        // To achieve 30% margin: price = cost / (1 - 0.30) = cost / 0.70
        // Cost of 70 with 30% margin = 70 / 0.70 = 100
        $price = $settings->calculateWithProfitMargin(70.00);

        expect($price)->toBe(100.00);
    });

    it('handles edge case of 100% profit margin', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 100.00,
        ]);

        // 100% margin would cause division by zero, so returns original cost
        $price = $settings->calculateWithProfitMargin(500.00);

        expect($price)->toBe(500.00);
    });

    it('uses default profit margin when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        // Default margin is 30%
        // Cost of 140 with 30% margin = 140 / 0.70 = 200
        $price = $settings->calculateWithProfitMargin(140.00);

        expect($price)->toBe(200.00);
    });

    it('calculates profit margin with different percentages', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'net_profit_margin_percent' => 25.00,
        ]);

        // To achieve 25% margin: price = cost / (1 - 0.25) = cost / 0.75
        // Cost of 75 with 25% margin = 75 / 0.75 = 100
        $price = $settings->calculateWithProfitMargin(75.00);

        expect($price)->toBe(100.00);
    });

    it('gets paint price from catalog by brand and type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'paint_catalog' => [
                'sherwin_williams' => [
                    'duration' => 75.00,
                    'emerald' => 95.00,
                ],
                'benjamin_moore' => [
                    'aura' => 90.00,
                ],
            ],
        ]);

        expect($settings->getPaintPrice('sherwin_williams', 'duration'))->toBe(75.00);
        expect($settings->getPaintPrice('sherwin_williams', 'emerald'))->toBe(95.00);
        expect($settings->getPaintPrice('benjamin_moore', 'aura'))->toBe(90.00);
    });

    it('normalizes brand and type names when getting paint price', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'paint_catalog' => [
                'sherwin_williams' => [
                    'super_paint' => 55.00,
                ],
            ],
        ]);

        // Should normalize "Sherwin Williams" to "sherwin_williams"
        // and "Super Paint" to "super_paint"
        expect($settings->getPaintPrice('Sherwin Williams', 'Super Paint'))->toBe(55.00);
        expect($settings->getPaintPrice('sherwin-williams', 'super-paint'))->toBe(55.00);
    });

    it('returns null for unknown paint brand or type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'paint_catalog' => [
                'sherwin_williams' => [
                    'duration' => 75.00,
                ],
            ],
        ]);

        expect($settings->getPaintPrice('unknown_brand', 'duration'))->toBeNull();
        expect($settings->getPaintPrice('sherwin_williams', 'unknown_type'))->toBeNull();
    });

    it('uses default paint catalog when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        // Default catalog has sherwin_williams duration at 75.00
        expect($settings->getPaintPrice('sherwin_williams', 'duration'))->toBe(75.00);
    });

    it('gets trim width by trim type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'trim_widths' => [
                'baseboard' => 4.0,
                'crown_molding' => 6.0,
                'door_casing' => 3.0,
            ],
        ]);

        expect($settings->getTrimWidth('baseboard'))->toBe(4.0);
        expect($settings->getTrimWidth('crown_molding'))->toBe(6.0);
        expect($settings->getTrimWidth('door_casing'))->toBe(3.0);
    });

    it('normalizes trim type names when getting width', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'trim_widths' => [
                'chair_rail' => 2.5,
            ],
        ]);

        expect($settings->getTrimWidth('Chair Rail'))->toBe(2.5);
        expect($settings->getTrimWidth('chair-rail'))->toBe(2.5);
    });

    it('returns null for unknown trim type', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'trim_widths' => [
                'baseboard' => 4.0,
            ],
        ]);

        expect($settings->getTrimWidth('unknown_trim'))->toBeNull();
    });

    it('uses default trim widths when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        // Default trim widths include baseboard at 4.0
        expect($settings->getTrimWidth('baseboard'))->toBe(4.0);
        expect($settings->getTrimWidth('crown_molding'))->toBe(6.0);
    });
});

describe('CompanySetting singleton instance', function () {
    it('creates instance with defaults when none exists', function () {
        $settings = CompanySetting::getInstance();

        expect($settings)->toBeInstanceOf(CompanySetting::class);
        expect($settings->exists)->toBeTrue();
    });

    it('returns existing instance when one exists', function () {
        $existing = CompanySetting::create([
            'company_name' => 'Existing Company',
            'default_tax_rate' => 8.5,
        ]);

        $settings = CompanySetting::getInstance();

        expect($settings->id)->toBe($existing->id);
        expect($settings->company_name)->toBe('Existing Company');
        expect((float) $settings->default_tax_rate)->toBe(8.5);
    });
});

describe('CompanySetting full address', function () {
    it('formats full address correctly', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'address_line_1' => '123 Main Street',
            'address_line_2' => 'Suite 100',
            'city' => 'Austin',
            'state' => 'TX',
            'zip_code' => '78701',
        ]);

        $fullAddress = $settings->full_address;

        expect($fullAddress)->toContain('123 Main Street');
        expect($fullAddress)->toContain('Suite 100');
        expect($fullAddress)->toContain('Austin');
        expect($fullAddress)->toContain('TX 78701');
    });

    it('returns null when no address set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        expect($settings->full_address)->toBeNull();
    });

    it('handles partial address', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'address_line_1' => '456 Oak Avenue',
            'city' => 'Dallas',
            'state' => 'TX',
            'zip_code' => '75201',
        ]);

        $fullAddress = $settings->full_address;

        expect($fullAddress)->toContain('456 Oak Avenue');
        expect($fullAddress)->toContain('Dallas');
        expect($fullAddress)->not->toContain('Suite');
    });
});

describe('CompanySetting warranty options', function () {
    it('returns custom warranty options when set', function () {
        $customOptions = [
            [
                'years' => 2,
                'name' => 'Basic Warranty',
                'description' => '2-year basic warranty',
                'price_multiplier' => 1.0,
            ],
            [
                'years' => 5,
                'name' => 'Extended Warranty',
                'description' => '5-year extended warranty',
                'price_multiplier' => 1.15,
            ],
        ];

        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
            'warranty_options' => $customOptions,
        ]);

        $options = $settings->getWarrantyOptions();

        expect($options)->toHaveCount(2);
        expect($options[0]['years'])->toBe(2);
        expect($options[1]['years'])->toBe(5);
    });

    it('returns default warranty options when not set', function () {
        $settings = CompanySetting::create([
            'company_name' => 'Test Company',
        ]);

        $options = $settings->getWarrantyOptions();

        expect($options)->toHaveCount(3);
        expect($options[0]['years'])->toBe(1);
        expect($options[1]['years'])->toBe(3);
        expect($options[2]['years'])->toBe(5);
    });
});

describe('CompanySetting default catalogs', function () {
    it('returns default paint catalog with expected brands', function () {
        $catalog = CompanySetting::getDefaultPaintCatalog();

        expect($catalog)->toHaveKey('sherwin_williams');
        expect($catalog)->toHaveKey('benjamin_moore');
        expect($catalog)->toHaveKey('ppg');

        expect($catalog['sherwin_williams'])->toHaveKey('duration');
        expect($catalog['sherwin_williams'])->toHaveKey('emerald');
        expect($catalog['benjamin_moore'])->toHaveKey('aura');
    });

    it('returns default trim widths with expected types', function () {
        $widths = CompanySetting::getDefaultTrimWidths();

        expect($widths)->toHaveKey('baseboard');
        expect($widths)->toHaveKey('crown_molding');
        expect($widths)->toHaveKey('door_casing');
        expect($widths)->toHaveKey('window_casing');
        expect($widths)->toHaveKey('chair_rail');

        expect($widths['baseboard'])->toBe(4.0);
        expect($widths['crown_molding'])->toBe(6.0);
    });

    it('returns default warranty options with expected tiers', function () {
        $options = CompanySetting::getDefaultWarrantyOptions();

        expect($options)->toHaveCount(3);

        $years = array_column($options, 'years');
        expect($years)->toContain(1);
        expect($years)->toContain(3);
        expect($years)->toContain(5);
    });
});
