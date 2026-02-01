<?php

use App\Models\Estimate;
use App\Models\EstimateOptionalItem;
use App\Models\EstimateTemplate;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimateOptionalItem model', function () {
    it('can be created with factory', function () {
        $item = EstimateOptionalItem::factory()->create();

        expect($item)->toBeInstanceOf(EstimateOptionalItem::class)
            ->and($item->id)->toBeGreaterThan(0);
    });

    it('belongs to an estimate', function () {
        $estimate = Estimate::factory()->create();
        $item = EstimateOptionalItem::factory()->forEstimate($estimate)->create();

        expect($item->estimate)->toBeInstanceOf(Estimate::class)
            ->and($item->estimate->id)->toBe($estimate->id);
    });

    it('can belong to a template', function () {
        $template = EstimateTemplate::factory()->create();
        $item = EstimateOptionalItem::factory()->forTemplate($template)->create();

        expect($item->template)->toBeInstanceOf(EstimateTemplate::class)
            ->and($item->template->id)->toBe($template->id);
    });

    it('calculates fixed price correctly', function () {
        $item = EstimateOptionalItem::factory()->fixed(250.00)->create();

        expect($item->calculatePrice(1000.00))->toBe(250.00)
            ->and($item->calculatePrice(5000.00))->toBe(250.00);
    });

    it('calculates percent price correctly', function () {
        $item = EstimateOptionalItem::factory()->percent(10.00)->create();

        expect($item->calculatePrice(1000.00))->toBe(100.00)
            ->and($item->calculatePrice(5000.00))->toBe(500.00);
    });

    it('can be selected', function () {
        $item = EstimateOptionalItem::factory()->create([
            'is_selected_by_customer' => false,
            'selected_at' => null,
        ]);

        expect($item->is_selected_by_customer)->toBeFalse()
            ->and($item->selected_at)->toBeNull();

        $item->select();

        expect($item->is_selected_by_customer)->toBeTrue()
            ->and($item->selected_at)->not->toBeNull();
    });

    it('can be deselected', function () {
        $item = EstimateOptionalItem::factory()->selected()->create();

        expect($item->is_selected_by_customer)->toBeTrue()
            ->and($item->selected_at)->not->toBeNull();

        $item->deselect();

        expect($item->is_selected_by_customer)->toBeFalse()
            ->and($item->selected_at)->toBeNull();
    });

    it('can be filtered by selected scope', function () {
        $estimate = Estimate::factory()->create();

        EstimateOptionalItem::factory()
            ->forEstimate($estimate)
            ->selected()
            ->count(3)
            ->create();

        EstimateOptionalItem::factory()
            ->forEstimate($estimate)
            ->notSelected()
            ->count(2)
            ->create();

        $selectedItems = EstimateOptionalItem::selected()->get();

        expect($selectedItems)->toHaveCount(3);
    });

    it('has correct pricing type constants', function () {
        expect(EstimateOptionalItem::PRICING_FIXED)->toBe('fixed')
            ->and(EstimateOptionalItem::PRICING_PERCENT)->toBe('percent');
    });

    it('casts decimal fields correctly', function () {
        $item = EstimateOptionalItem::factory()->fixed(123.45)->create([
            'percent_of_total' => 5.50,
        ]);

        $item->refresh();

        expect($item->price)->toBe('123.45')
            ->and($item->percent_of_total)->toBe('5.50');
    });

    it('casts boolean field correctly', function () {
        $selectedItem = EstimateOptionalItem::factory()->selected()->create();
        $notSelectedItem = EstimateOptionalItem::factory()->notSelected()->create();

        expect($selectedItem->is_selected_by_customer)->toBeTrue()
            ->and($notSelectedItem->is_selected_by_customer)->toBeFalse();
    });

    it('casts datetime field correctly', function () {
        $item = EstimateOptionalItem::factory()->selected()->create();

        expect($item->selected_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
    });
});
