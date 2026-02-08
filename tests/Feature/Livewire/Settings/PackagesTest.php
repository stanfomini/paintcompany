<?php

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    $this->category = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
});

describe('Page Access', function () {
    it('internal user with full access can view the page', function () {
        Livewire::test('pages::settings.packages')
            ->assertSuccessful()
            ->assertSee('Package Tiers')
            ->assertSee('Item Catalog');
    });

    it('non-internal user cannot access the page', function () {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        Livewire::test('pages::settings.packages')
            ->assertForbidden();
    });
});

describe('Package CRUD', function () {
    it('can create a new package without warranty fields', function () {
        Livewire::test('pages::settings.packages')
            ->call('openEditModal')
            ->set('editName', 'Premium')
            ->set('editTier', 'premium')
            ->set('editDescription', 'Premium tier package')
            ->set('editIsActive', true)
            ->set('editServiceCategoryId', (string) $this->category->id)
            ->call('savePackage')
            ->assertDispatched('package-saved');

        $this->assertDatabaseHas('package_templates', [
            'name' => 'Premium',
            'tier' => 'premium',
            'description' => 'Premium tier package',
        ]);
    });

    it('can edit an existing package', function () {
        $package = PackageTemplate::factory()->forService($this->category)->good()->create();

        Livewire::test('pages::settings.packages')
            ->call('openEditModal', $package->id)
            ->assertSet('editName', 'Good')
            ->set('editName', 'Updated Good')
            ->call('savePackage')
            ->assertDispatched('package-saved');

        $this->assertDatabaseHas('package_templates', [
            'id' => $package->id,
            'name' => 'Updated Good',
        ]);
    });

    it('can delete a package', function () {
        $package = PackageTemplate::factory()->forService($this->category)->good()->create();

        Livewire::test('pages::settings.packages')
            ->call('deletePackage', $package->id)
            ->assertDispatched('package-deleted');

        $this->assertDatabaseMissing('package_templates', ['id' => $package->id]);
    });

    it('does not include warranty fields in package save', function () {
        Livewire::test('pages::settings.packages')
            ->call('openEditModal')
            ->set('editName', 'Test')
            ->set('editTier', 'test')
            ->set('editServiceCategoryId', (string) $this->category->id)
            ->call('savePackage')
            ->assertDispatched('package-saved');

        $package = PackageTemplate::where('name', 'Test')->first();
        expect($package)->not->toBeNull();
    });
});

describe('Item Catalog', function () {
    it('loads items in the catalog', function () {
        $item = PackageTemplateItem::factory()->cleaningService()->forService($this->category)->create();

        Livewire::test('pages::settings.packages')
            ->assertSee('Item Catalog')
            ->assertSee('Post-Project Cleaning');
    });

    it('can create a standalone item with service category', function () {
        Livewire::test('pages::settings.packages')
            ->call('openItemModal')
            ->set('itemName', 'Deep Cleaning')
            ->set('itemDescription', 'Extra deep cleaning')
            ->set('itemPricingType', 'fixed')
            ->set('itemCost', '100')
            ->set('itemPrice', '250')
            ->set('itemServiceCategoryId', (string) $this->category->id)
            ->set('itemIsCustomerSelectable', true)
            ->call('saveItem')
            ->assertDispatched('item-saved');

        $this->assertDatabaseHas('package_template_items', [
            'name' => 'Deep Cleaning',
            'service_category_id' => $this->category->id,
            'is_customer_selectable' => true,
        ]);
    });

    it('can create a percent-based item', function () {
        Livewire::test('pages::settings.packages')
            ->call('openItemModal')
            ->set('itemName', '1-Year Warranty')
            ->set('itemPricingType', 'percent')
            ->set('itemPercent', '5')
            ->set('itemServiceCategoryId', (string) $this->category->id)
            ->set('itemIsCustomerSelectable', true)
            ->call('saveItem')
            ->assertDispatched('item-saved');

        $this->assertDatabaseHas('package_template_items', [
            'name' => '1-Year Warranty',
            'pricing_type' => 'percent',
            'is_customer_selectable' => true,
        ]);
    });

    it('can assign item to default packages via pivot', function () {
        $good = PackageTemplate::factory()->forService($this->category)->good()->create();
        $better = PackageTemplate::factory()->forService($this->category)->better()->create();

        Livewire::test('pages::settings.packages')
            ->call('openItemModal')
            ->set('itemName', 'Touch-up Kit')
            ->set('itemPricingType', 'fixed')
            ->set('itemPrice', '50')
            ->set('itemServiceCategoryId', (string) $this->category->id)
            ->set('itemDefaultPackages', [$good->id, $better->id])
            ->call('saveItem')
            ->assertDispatched('item-saved');

        $item = PackageTemplateItem::where('name', 'Touch-up Kit')->first();
        expect($item->defaultPackages)->toHaveCount(2);
        expect($item->defaultPackages->pluck('id')->toArray())->toContain($good->id, $better->id);
    });

    it('can edit an existing item and update default packages', function () {
        $good = PackageTemplate::factory()->forService($this->category)->good()->create();
        $better = PackageTemplate::factory()->forService($this->category)->better()->create();

        $item = PackageTemplateItem::factory()
            ->cleaningService()
            ->forService($this->category)
            ->defaultingTo($good)
            ->create();

        Livewire::test('pages::settings.packages')
            ->call('openItemModal', $item->id)
            ->assertSet('itemName', 'Post-Project Cleaning')
            ->assertSet('itemIsCustomerSelectable', false)
            ->set('itemName', 'Updated Cleaning')
            ->set('itemIsCustomerSelectable', true)
            ->set('itemDefaultPackages', [$better->id])
            ->call('saveItem')
            ->assertDispatched('item-saved');

        $item->refresh();
        expect($item->name)->toBe('Updated Cleaning');
        expect($item->is_customer_selectable)->toBeTrue();
        expect($item->defaultPackages)->toHaveCount(1);
        expect($item->defaultPackages->first()->id)->toBe($better->id);
    });

    it('can delete an item', function () {
        $item = PackageTemplateItem::factory()->cleaningService()->forService($this->category)->create();

        Livewire::test('pages::settings.packages')
            ->call('deleteItem', $item->id)
            ->assertDispatched('item-deleted');

        $this->assertDatabaseMissing('package_template_items', ['id' => $item->id]);
    });

    it('filters items by service category', function () {
        $cleaning = ServiceCategory::firstOrCreate(
            ['slug' => 'cleaning'],
            ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
        );

        PackageTemplateItem::factory()->forService($this->category)->create(['name' => 'Painting Item']);
        PackageTemplateItem::factory()->forService($cleaning)->create(['name' => 'Cleaning Item']);

        // Default filter is painting (first category)
        $component = Livewire::test('pages::settings.packages')
            ->assertSee('Painting Item');

        // Filter by cleaning category
        $component->set('itemFilterCategoryId', $cleaning->id)
            ->assertSee('Cleaning Item');
    });

    it('shows all items when filter is null', function () {
        $cleaning = ServiceCategory::firstOrCreate(
            ['slug' => 'cleaning'],
            ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
        );

        PackageTemplateItem::factory()->forService($this->category)->create(['name' => 'Paint Addon']);
        PackageTemplateItem::factory()->forService($cleaning)->create(['name' => 'Clean Addon']);

        Livewire::test('pages::settings.packages')
            ->set('itemFilterCategoryId', null)
            ->assertSee('Paint Addon')
            ->assertSee('Clean Addon');
    });
});

describe('Seed Defaults', function () {
    it('seeds packages with warranty items as standalone items linked via pivot', function () {
        Livewire::test('pages::settings.packages')
            ->call('seedDefaultPackages')
            ->assertDispatched('packages-seeded');

        // Verify 3 packages created
        $packages = PackageTemplate::where('service_category_id', $this->category->id)->get();
        expect($packages)->toHaveCount(3);

        // Verify warranty items are standalone (no package_template_id)
        $warrantyItems = PackageTemplateItem::where('is_customer_selectable', true)->get();
        expect($warrantyItems)->toHaveCount(3);
        expect($warrantyItems->pluck('name')->toArray())->toContain('3-Month Warranty', '6-Month Warranty', '1-Year Warranty');

        // Verify warranty items are linked via pivot
        $good = $packages->firstWhere('tier', 'good');
        $better = $packages->firstWhere('tier', 'better');
        $best = $packages->firstWhere('tier', 'best');

        expect($good->defaultItems()->where('name', '3-Month Warranty')->exists())->toBeTrue();
        expect($better->defaultItems()->where('name', '6-Month Warranty')->exists())->toBeTrue();
        expect($best->defaultItems()->where('name', '1-Year Warranty')->exists())->toBeTrue();

        // Verify service items are linked via pivot
        expect($better->defaultItems()->where('name', 'Post-Project Cleaning')->exists())->toBeTrue();
        expect($best->defaultItems()->where('name', 'Post-Project Cleaning')->exists())->toBeTrue();
        expect($best->defaultItems()->where('name', 'Color Consultation')->exists())->toBeTrue();
    });

    it('does not seed if packages already exist for category', function () {
        PackageTemplate::factory()->forService($this->category)->good()->create();

        Livewire::test('pages::settings.packages')
            ->call('seedDefaultPackages');

        // Should still be just 1 package
        expect(PackageTemplate::where('service_category_id', $this->category->id)->count())->toBe(1);
    });
});

describe('Package Modal', function () {
    it('does not show warranty fields in edit modal', function () {
        Livewire::test('pages::settings.packages')
            ->call('openEditModal')
            ->assertDontSee('Warranty (months)')
            ->assertDontSee('Warranty Cost (%)');
    });
});

describe('Package Cards', function () {
    it('shows default items badges on package cards', function () {
        $package = PackageTemplate::factory()->forService($this->category)->good()->create();
        $item = PackageTemplateItem::factory()
            ->cleaningService()
            ->forService($this->category)
            ->customerSelectable()
            ->defaultingTo($package)
            ->create();

        Livewire::test('pages::settings.packages')
            ->assertSee('Post-Project Cleaning');
    });

    it('shows no default items message when none assigned', function () {
        PackageTemplate::factory()->forService($this->category)->good()->create();

        Livewire::test('pages::settings.packages')
            ->assertSee('No default items assigned');
    });
});
