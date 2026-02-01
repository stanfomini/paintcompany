<?php

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateUsage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('has fillable fields', function () {
    $usage = new EstimateTemplateUsage;

    expect($usage->getFillable())->toBe([
        'estimate_id',
        'template_id',
        'quantity',
        'coats',
        'calculated_price',
        'resultable_type',
        'resultable_id',
        'sort_order',
    ]);
});

it('casts fields correctly', function () {
    $usage = EstimateTemplateUsage::factory()->create([
        'quantity' => '100.50',
        'coats' => '2',
        'sort_order' => '5',
    ]);

    expect($usage->quantity)->toBe('100.50')
        ->and($usage->coats)->toBeInt()
        ->and($usage->sort_order)->toBeInt();
});

it('belongs to an estimate', function () {
    $usage = EstimateTemplateUsage::factory()->create();

    expect($usage->estimate)->toBeInstanceOf(Estimate::class);
});

it('belongs to a template', function () {
    $usage = EstimateTemplateUsage::factory()->create();

    expect($usage->template)->toBeInstanceOf(EstimateTemplate::class);
});

it('has a polymorphic resultable relationship', function () {
    $item = EstimateItem::factory()->create();
    $usage = EstimateTemplateUsage::factory()->create([
        'resultable_type' => EstimateItem::class,
        'resultable_id' => $item->id,
    ]);

    expect($usage->resultable)->toBeInstanceOf(EstimateItem::class)
        ->and($usage->resultable->id)->toBe($item->id);
});

it('recalculates price on save using template', function () {
    $template = EstimateTemplate::factory()->create([
        'pricing_method' => 'fixed',
        'base_price' => 500.00,
    ]);

    $usage = EstimateTemplateUsage::factory()->create([
        'template_id' => $template->id,
        'quantity' => 100,
        'coats' => 2,
    ]);

    // The calculated_price should be set to the template's calculatePrice result
    expect((float) $usage->calculated_price)->toBe(500.00);
});

it('recalculates price on update', function () {
    $template = EstimateTemplate::factory()->create([
        'pricing_method' => 'per_sqft',
        'per_sqft_rate' => 2.50,
    ]);

    $usage = EstimateTemplateUsage::factory()->create([
        'template_id' => $template->id,
        'quantity' => 100,
        'coats' => 2,
    ]);

    // 100 sqft * $2.50/sqft = $250
    expect((float) $usage->calculated_price)->toBe(250.00);

    // Update quantity
    $usage->quantity = 200;
    $usage->save();
    $usage->refresh();

    // 200 sqft * $2.50/sqft = $500
    expect((float) $usage->calculated_price)->toBe(500.00);
});
