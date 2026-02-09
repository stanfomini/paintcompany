<?php

use App\Models\Estimate;
use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

it('selects tier directly without warranty picker step', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create(['service_category_id' => $this->category->id]);
    PackageTemplate::factory()->better()->create(['service_category_id' => $this->category->id]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good')
        ->assertSet('selectedTier', 'good')
        ->assertDispatched('tier-selected');
});

it('warranty items appear in template items with is_warranty flag', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
    ]);

    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 4.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    $templates = $component->get('packageTemplates');
    $betterTemplate = collect($templates)->firstWhere('tier', 'better');

    $items = collect($betterTemplate['items']);
    $warrantyItems = $items->where('is_warranty', true);

    expect($warrantyItems)->toHaveCount(1)
        ->and($warrantyItems->first()['name'])->toBe('1-Year Warranty')
        ->and($warrantyItems->first()['price'])->toBe(200.0); // 4% of 5000
});

it('customer can toggle warranty items on and off', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);

    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 4.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    $warrantyKey = 'item_'.$warrantyItem->id;

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->assertSet("selectedPackageItems.{$warrantyKey}", true);

    // Toggle off
    $component->call('togglePackageItem', $warrantyKey)
        ->assertSet("selectedPackageItems.{$warrantyKey}", false);

    // Toggle back on
    $component->call('togglePackageItem', $warrantyKey)
        ->assertSet("selectedPackageItems.{$warrantyKey}", true);
});

it('warranty total updates when warranty item is toggled', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 10000,
    ]);

    $template = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);

    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 2.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    $warrantyKey = 'item_'.$warrantyItem->id;

    // With warranty: total = 10000 + 200 = 10200
    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);
    expect($component->get('selectedTemplateTotal'))->toBe(10200.0);
    expect($component->get('selectedTemplateWarrantyTotal'))->toBe(200.0);

    // Toggle off warranty: total = 10000
    $component->call('togglePackageItem', $warrantyKey);
    expect($component->get('selectedTemplateTotal'))->toBe(10000.0);
    expect($component->get('selectedTemplateWarrantyTotal'))->toBe(0.0);
});

it('different templates can have different warranty items', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    $goodTemplate = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);
    $bestTemplate = PackageTemplate::factory()->best()->create([
        'service_category_id' => $this->category->id,
    ]);

    // Good gets 6-month warranty at 2%
    $shortWarranty = PackageTemplateItem::factory()->warranty(6, 2.0)->create([
        'name' => '6-Month Warranty',
        'service_category_id' => $this->category->id,
    ]);
    $shortWarranty->defaultPackages()->attach($goodTemplate->id);

    // Best gets 2-year warranty at 6%
    $longWarranty = PackageTemplateItem::factory()->warranty(24, 6.0)->create([
        'name' => '2-Year Warranty',
        'service_category_id' => $this->category->id,
    ]);
    $longWarranty->defaultPackages()->attach($bestTemplate->id);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    $templates = $component->get('packageTemplates');

    $good = collect($templates)->firstWhere('tier', 'good');
    $best = collect($templates)->firstWhere('tier', 'best');

    $goodWarranty = collect($good['items'])->where('is_warranty', true);
    $bestWarranty = collect($best['items'])->where('is_warranty', true);

    expect($goodWarranty)->toHaveCount(1)
        ->and($goodWarranty->first()['name'])->toBe('6-Month Warranty')
        ->and($goodWarranty->first()['price'])->toBe(100.0); // 2% of 5000

    expect($bestWarranty)->toHaveCount(1)
        ->and($bestWarranty->first()['name'])->toBe('2-Year Warranty')
        ->and($bestWarranty->first()['price'])->toBe(300.0); // 6% of 5000
});

it('template without warranty items shows no warranty in card', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);

    $component = Livewire::test('public.proposal-packages', ['estimate' => $estimate]);

    $templates = $component->get('packageTemplates');
    $goodTemplate = collect($templates)->firstWhere('tier', 'good');

    $warrantyItems = collect($goodTemplate['items'])->where('is_warranty', true);
    expect($warrantyItems)->toHaveCount(0);
});

it('logs activity when tier is selected', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 2000,
    ]);

    PackageTemplate::factory()->good()->create(['service_category_id' => $this->category->id]);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'good');

    $activity = $estimate->activities()->where('activity_type', 'package_tier_selected')->first();
    expect($activity)->not->toBeNull();
    expect($activity->metadata['tier'])->toBe('good');
});

it('warranty items show with blue styling in breakdown', function () {
    $estimate = Estimate::factory()->create([
        'status' => 'sent',
        'areas_subtotal' => 5000,
    ]);

    $template = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
    ]);

    $warrantyItem = PackageTemplateItem::factory()->warranty(12, 5.0)->create([
        'service_category_id' => $this->category->id,
    ]);
    $warrantyItem->defaultPackages()->attach($template->id);

    Livewire::test('public.proposal-packages', ['estimate' => $estimate])
        ->call('selectTier', 'better')
        ->assertSee('1-Year Warranty')
        ->assertSee('Included - click to remove');
});
