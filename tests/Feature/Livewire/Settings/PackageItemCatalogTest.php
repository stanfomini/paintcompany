<?php

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);

    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

it('creates standalone item without package association', function () {
    Livewire::test('pages::settings.packages')
        ->call('openItemModal')
        ->set('itemName', 'Touch-up Kit')
        ->set('itemDescription', 'A kit for minor touch-ups')
        ->set('itemPricingType', 'fixed')
        ->set('itemCost', '25')
        ->set('itemPrice', '75')
        ->call('saveItem');

    $item = PackageTemplateItem::where('name', 'Touch-up Kit')->first();
    expect($item)->not->toBeNull()
        ->and($item->package_template_id)->toBeNull()
        ->and((float) $item->price)->toBe(75.0);
});

it('creates item with service category', function () {
    Livewire::test('pages::settings.packages')
        ->call('openItemModal')
        ->set('itemName', 'Pressure Washing')
        ->set('itemPricingType', 'fixed')
        ->set('itemPrice', '350')
        ->set('itemServiceCategoryId', (string) $this->category->id)
        ->call('saveItem');

    $item = PackageTemplateItem::where('name', 'Pressure Washing')->first();
    expect($item->service_category_id)->toBe($this->category->id);
});

it('creates item with is_customer_selectable flag', function () {
    Livewire::test('pages::settings.packages')
        ->call('openItemModal')
        ->set('itemName', 'Extended Warranty')
        ->set('itemPricingType', 'percent')
        ->set('itemPercent', '5')
        ->set('itemIsCustomerSelectable', true)
        ->call('saveItem');

    $item = PackageTemplateItem::where('name', 'Extended Warranty')->first();
    expect($item->is_customer_selectable)->toBeTrue();
});

it('assigns item to default packages via pivot', function () {
    $template = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
    ]);

    Livewire::test('pages::settings.packages')
        ->call('openItemModal')
        ->set('itemName', 'Color Consultation')
        ->set('itemPricingType', 'fixed')
        ->set('itemPrice', '125')
        ->set('itemDefaultPackages', [$template->id])
        ->call('saveItem');

    $item = PackageTemplateItem::where('name', 'Color Consultation')->first();
    expect($item->defaultPackages)->toHaveCount(1)
        ->and($item->defaultPackages->first()->id)->toBe($template->id);
});

it('edits item and updates default packages', function () {
    $template1 = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);
    $template2 = PackageTemplate::factory()->better()->create([
        'service_category_id' => $this->category->id,
    ]);

    $item = PackageTemplateItem::factory()->fixed(100, 250)->create([
        'name' => 'Original Name',
        'service_category_id' => $this->category->id,
    ]);
    $item->defaultPackages()->attach($template1->id);

    Livewire::test('pages::settings.packages')
        ->call('openItemModal', $item->id)
        ->set('itemName', 'Updated Name')
        ->set('itemDefaultPackages', [$template2->id])
        ->call('saveItem');

    $item->refresh();
    expect($item->name)->toBe('Updated Name')
        ->and($item->defaultPackages)->toHaveCount(1)
        ->and($item->defaultPackages->first()->id)->toBe($template2->id);
});

it('deletes item and cascade removes pivot entries', function () {
    $template = PackageTemplate::factory()->good()->create([
        'service_category_id' => $this->category->id,
    ]);

    $item = PackageTemplateItem::factory()->fixed(100, 250)->create([
        'service_category_id' => $this->category->id,
    ]);
    $item->defaultPackages()->attach($template->id);

    $itemId = $item->id;

    Livewire::test('pages::settings.packages')
        ->call('deleteItem', $itemId);

    expect(PackageTemplateItem::find($itemId))->toBeNull();
    expect(\DB::table('package_template_item_defaults')->where('package_template_item_id', $itemId)->exists())->toBeFalse();
});

it('loads items list in settings page', function () {
    PackageTemplateItem::factory()->fixed(100, 250)->create([
        'name' => 'Cleaning Service',
        'service_category_id' => $this->category->id,
    ]);

    $component = Livewire::test('pages::settings.packages');

    $items = $component->get('items');
    expect($items)->toHaveCount(1)
        ->and($items[0]['name'])->toBe('Cleaning Service');
});

it('filters items by service category', function () {
    $otherCategory = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'description' => 'Post-painting cleanup', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );

    PackageTemplateItem::factory()->create([
        'name' => 'Painting Item',
        'service_category_id' => $this->category->id,
    ]);

    PackageTemplateItem::factory()->create([
        'name' => 'Cleaning Item',
        'service_category_id' => $otherCategory->id,
    ]);

    $component = Livewire::test('pages::settings.packages')
        ->set('itemFilterCategoryId', $this->category->id);

    $items = $component->get('items');
    $names = collect($items)->pluck('name')->toArray();
    expect($names)->toContain('Painting Item')
        ->and($names)->not->toContain('Cleaning Item');
});

it('seeds default packages with warranty items', function () {
    $component = Livewire::test('pages::settings.packages')
        ->set('filterCategoryId', $this->category->id)
        ->call('seedDefaultPackages');

    // Check warranty items were created
    $warrantyItems = PackageTemplateItem::where('is_customer_selectable', true)
        ->where('pricing_type', 'percent')
        ->get();

    expect($warrantyItems->count())->toBeGreaterThanOrEqual(1);

    // Verify warranty items are linked to templates via pivot
    foreach ($warrantyItems as $item) {
        expect($item->defaultPackages->count())->toBeGreaterThanOrEqual(1);
    }
});

it('warranty items created by seed have is_customer_selectable true', function () {
    Livewire::test('pages::settings.packages')
        ->set('filterCategoryId', $this->category->id)
        ->call('seedDefaultPackages');

    $warrantyItems = PackageTemplateItem::where('is_customer_selectable', true)->get();

    foreach ($warrantyItems as $item) {
        expect($item->is_customer_selectable)->toBeTrue()
            ->and($item->pricing_type)->toBe('percent');
    }
});

it('shows items in standalone catalog section', function () {
    PackageTemplateItem::factory()->fixed(100, 250)->create([
        'name' => 'Standalone Service',
        'service_category_id' => $this->category->id,
    ]);

    Livewire::test('pages::settings.packages')
        ->assertSee('Item Catalog')
        ->assertSee('Standalone Service');
});
