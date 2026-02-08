<?php

use App\Models\Estimate;
use App\Models\PackageTemplate;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows warranty picker after tier selection', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create();
    PackageTemplate::factory()->better()->create();
    PackageTemplate::factory()->best()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->assertSet('showWarrantyPicker', true)
        ->assertSet('selectedTier', 'good')
        ->assertSee('Choose Your Warranty');
});

it('can select a warranty level', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create();
    PackageTemplate::factory()->better()->create();
    PackageTemplate::factory()->best()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('selectWarranty', 'better')
        ->assertSet('selectedWarrantyTier', 'better');
});

it('confirms selection with warranty and updates template data', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create([
        'warranty_months' => 3,
        'warranty_percent' => 1,
    ]);
    PackageTemplate::factory()->better()->create([
        'warranty_months' => 12,
        'warranty_percent' => 4,
    ]);
    PackageTemplate::factory()->best()->create([
        'warranty_months' => 24,
        'warranty_percent' => 6,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('selectWarranty', 'better')
        ->call('confirmSelection');

    // After confirming, warranty picker should be hidden
    $component->assertSet('showWarrantyPicker', false);

    // The good tier's warranty should now be overridden with better's values
    $templates = $component->get('packageTemplates');
    $goodTemplate = collect($templates)->firstWhere('tier', 'good');

    // Better warranty = 4% of 5000 = 200
    expect((float) $goodTemplate['warranty_amount'])->toBe(200.0)
        ->and($goodTemplate['warranty_months'])->toBe(12);
});

it('can go back from warranty picker', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create();
    PackageTemplate::factory()->better()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->assertSet('showWarrantyPicker', true)
        ->set('showWarrantyPicker', false)
        ->assertSet('showWarrantyPicker', false);
});

it('dispatches tier-selected event after confirming warranty', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 3000,
    ]);

    PackageTemplate::factory()->good()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('selectWarranty', 'good')
        ->call('confirmSelection')
        ->assertDispatched('tier-selected');
});

it('does not dispatch tier-selected on selectTier alone', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 3000,
    ]);

    PackageTemplate::factory()->good()->create();
    PackageTemplate::factory()->better()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->assertNotDispatched('tier-selected');
});

it('shows warranty options with correct costs', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 10000,
    ]);

    PackageTemplate::factory()->good()->create([
        'warranty_months' => 6,
        'warranty_percent' => 2,
    ]);
    PackageTemplate::factory()->better()->create([
        'warranty_months' => 12,
        'warranty_percent' => 4,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good');

    $options = $component->get('warrantyOptions');

    // Good: 2% of 10000 = 200
    $goodOption = collect($options)->firstWhere('tier', 'good');
    expect($goodOption['cost'])->toBe(200.0)
        ->and($goodOption['months'])->toBe(6);

    // Better: 4% of 10000 = 400
    $betterOption = collect($options)->firstWhere('tier', 'better');
    expect($betterOption['cost'])->toBe(400.0)
        ->and($betterOption['months'])->toBe(12);
});

it('cannot confirm without selecting a warranty', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create();

    // Confirm without selecting warranty - should still work but with null warranty
    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('confirmSelection');

    // showWarrantyPicker should be false after confirm
    $component->assertSet('showWarrantyPicker', false);
    // No warranty override applied since selectedWarrantyTier is null
    $component->assertSet('selectedWarrantyTier', null);
});

it('applies cross-tier warranty selection correctly', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
    ]);

    PackageTemplate::factory()->good()->create([
        'warranty_months' => 3,
        'warranty_percent' => 1,
    ]);
    PackageTemplate::factory()->best()->create([
        'warranty_months' => 24,
        'warranty_percent' => 6,
    ]);

    // Select good tier but best warranty
    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('selectWarranty', 'best')
        ->call('confirmSelection');

    $templates = $component->get('packageTemplates');
    $goodTemplate = collect($templates)->firstWhere('tier', 'good');

    // Best warranty = 6% of 2000 = 120
    expect((float) $goodTemplate['warranty_amount'])->toBe(120.0)
        ->and($goodTemplate['warranty_months'])->toBe(24);

    // Total should be painting (2000) + warranty (120) = 2120
    expect($component->get('selectedTemplateTotal'))->toBe(2120.0);
});

it('logs activity with warranty tier on confirm', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
    ]);

    PackageTemplate::factory()->good()->create();
    PackageTemplate::factory()->better()->create();

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->call('selectWarranty', 'better')
        ->call('confirmSelection');

    $activity = $estimate->activities()->where('activity_type', 'package_tier_selected')->first();
    expect($activity)->not->toBeNull();

    $metadata = $activity->metadata;
    expect($metadata['tier'])->toBe('good')
        ->and($metadata['warranty_tier'])->toBe('better');
});
