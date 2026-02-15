<?php

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;

describe('PackageTemplate', function () {
    it('can be created with factory', function () {
        $template = PackageTemplate::factory()->good()->create();

        expect($template)->toBeInstanceOf(PackageTemplate::class);
        expect($template->tier)->toBe(PackageTemplate::TIER_GOOD);
        expect($template->name)->toBe('Good');
    });

    it('has items relationship', function () {
        $template = PackageTemplate::factory()->create();
        $item = PackageTemplateItem::factory()->forPackageTemplate($template)->create();

        expect($template->items)->toHaveCount(1);
        expect($template->items->first()->id)->toBe($item->id);
    });

    it('calculates warranty cost correctly', function () {
        $template = PackageTemplate::factory()->create([
            'warranty_percent' => 5.00,
        ]);

        $paintingTotal = 2000;
        $warrantyCost = $template->calculateWarrantyCost($paintingTotal);

        expect($warrantyCost)->toBe(100.0); // 5% of 2000
    });

    it('calculates package total with warranty and items', function () {
        $template = PackageTemplate::factory()->create([
            'warranty_percent' => 3.00,
        ]);

        // Add items with fixed pricing
        PackageTemplateItem::factory()->forPackageTemplate($template)->create([
            'pricing_type' => 'fixed',
            'price' => 400,
            'is_included_by_default' => true,
        ]);
        PackageTemplateItem::factory()->forPackageTemplate($template)->create([
            'pricing_type' => 'fixed',
            'price' => 125,
            'is_included_by_default' => true,
        ]);

        $paintingTotal = 2920;
        $packageTotal = $template->calculatePackageTotal($paintingTotal);

        // $2920 + 3% warranty ($87.60) + $400 + $125 = $3532.60
        expect($packageTotal)->toBe(3532.60);
    });

    it('generates warranty description for months', function () {
        $template = PackageTemplate::factory()->create(['warranty_months' => 6]);
        expect($template->warranty_description)->toBe('6-Month Warranty');
    });

    it('generates warranty description for 1 year', function () {
        $template = PackageTemplate::factory()->create(['warranty_months' => 12]);
        expect($template->warranty_description)->toBe('1-Year Warranty');
    });

    it('generates warranty description for multiple years', function () {
        $template = PackageTemplate::factory()->create(['warranty_months' => 24]);
        expect($template->warranty_description)->toBe('2-Year Warranty');
    });

    it('generates no warranty description for zero months', function () {
        $template = PackageTemplate::factory()->create(['warranty_months' => 0]);
        expect($template->warranty_description)->toBe('No warranty');
    });

    it('scopes to active templates', function () {
        PackageTemplate::factory()->create(['is_active' => true, 'tier' => 'good']);
        PackageTemplate::factory()->create(['is_active' => false, 'tier' => 'better']);

        expect(PackageTemplate::active()->count())->toBe(1);
    });

    it('gets default template', function () {
        PackageTemplate::factory()->create(['is_default' => false, 'tier' => 'good']);
        $default = PackageTemplate::factory()->create(['is_default' => true, 'tier' => 'better']);

        expect(PackageTemplate::getDefault()->id)->toBe($default->id);
    });

    it('has correct tier constants', function () {
        expect(PackageTemplate::TIER_GOOD)->toBe('good');
        expect(PackageTemplate::TIER_BETTER)->toBe('better');
        expect(PackageTemplate::TIER_BEST)->toBe('best');
    });
});

describe('PackageTemplateItem', function () {
    it('can be created with factory', function () {
        $item = PackageTemplateItem::factory()->cleaningService()->create();

        expect($item)->toBeInstanceOf(PackageTemplateItem::class);
        expect($item->name)->toBe('Post-Project Cleaning');
    });

    it('calculates fixed price correctly', function () {
        $item = PackageTemplateItem::factory()->fixed(cost: 200, price: 400)->create();

        expect($item->calculatePrice(5000))->toBe(400.0); // Fixed price regardless of total
    });

    it('calculates percentage price correctly', function () {
        $item = PackageTemplateItem::factory()->percent(5)->create();

        expect($item->calculatePrice(2000))->toBe(100.0); // 5% of 2000
    });

    it('displays fixed price correctly', function () {
        $item = PackageTemplateItem::factory()->create([
            'pricing_type' => 'fixed',
            'price' => 399.99,
        ]);

        expect($item->price_display)->toBe('$399.99');
    });

    it('displays percentage correctly', function () {
        $item = PackageTemplateItem::factory()->create([
            'pricing_type' => 'percent',
            'percent' => 5.5,
        ]);

        expect($item->price_display)->toBe('5.5%');
    });

    it('belongs to package template', function () {
        $template = PackageTemplate::factory()->create();
        $item = PackageTemplateItem::factory()->forPackageTemplate($template)->create();

        expect($item->packageTemplate->id)->toBe($template->id);
    });
});

describe('Package template value-add scenario', function () {
    it('calculates good/better/best tiers correctly', function () {
        $paintingTotal = 2920.00;

        // Good: no warranty, no add-ons
        $good = PackageTemplate::factory()->good()->create();

        // Better: no warranty + cleaning
        $better = PackageTemplate::factory()->better()->create();
        PackageTemplateItem::factory()->forPackageTemplate($better)->create([
            'name' => 'Cleaning Service',
            'pricing_type' => 'fixed',
            'price' => 400,
            'is_included_by_default' => true,
        ]);

        // Best: no warranty + cleaning + consultation
        $best = PackageTemplate::factory()->best()->create();
        PackageTemplateItem::factory()->forPackageTemplate($best)->create([
            'name' => 'Cleaning Service',
            'pricing_type' => 'fixed',
            'price' => 400,
            'is_included_by_default' => true,
        ]);
        PackageTemplateItem::factory()->forPackageTemplate($best)->create([
            'name' => 'Color Consultation',
            'pricing_type' => 'fixed',
            'price' => 125,
            'is_included_by_default' => true,
        ]);

        // Good: $2920 (no extras)
        expect(round($good->calculatePackageTotal($paintingTotal), 2))->toBe(2920.00);

        // Better: $2920 + $400 = $3320.00
        expect(round($better->calculatePackageTotal($paintingTotal), 2))->toBe(3320.00);

        // Best: $2920 + $400 + $125 = $3445.00
        expect(round($best->calculatePackageTotal($paintingTotal), 2))->toBe(3445.00);
    });
});
