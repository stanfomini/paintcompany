<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

describe('Service Categories Page Access', function () {

    it('internal user with full access can view the page', function () {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'sort_order' => 1]
        );

        Livewire::test('pages::settings.service-categories')
            ->assertSuccessful()
            ->assertSee('Service Categories')
            ->assertSee('Painting');
    });

    it('non-internal customer user cannot access the page', function () {
        $customer = User::factory()->customer()->create();
        $this->actingAs($customer);

        Livewire::test('pages::settings.service-categories')
            ->assertForbidden();
    });

    it('non-internal agent user cannot access the page', function () {
        $agent = User::factory()->agent()->create();
        $this->actingAs($agent);

        Livewire::test('pages::settings.service-categories')
            ->assertForbidden();
    });

    it('non-internal crew user cannot access the page', function () {
        $crew = User::factory()->crew()->create();
        $this->actingAs($crew);

        Livewire::test('pages::settings.service-categories')
            ->assertForbidden();
    });

    it('settings route is accessible for internal user', function () {
        $this->get(route('service-categories.edit'))
            ->assertSuccessful()
            ->assertSee('Service Categories');
    });
});

describe('Create Service Category', function () {

    it('can create a new service category', function () {
        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->assertSet('showModal', true)
            ->set('name', 'Pressure Washing')
            ->set('description', 'High pressure exterior cleaning')
            ->set('icon', 'sparkles')
            ->set('color', 'green')
            ->set('is_active', true)
            ->call('save')
            ->assertSet('showModal', false)
            ->assertDispatched('service-categories-updated');

        $this->assertDatabaseHas('service_categories', [
            'name' => 'Pressure Washing',
            'slug' => 'pressure-washing',
            'description' => 'High pressure exterior cleaning',
            'icon' => 'sparkles',
            'color' => 'green',
            'is_active' => true,
        ]);
    });

    it('validates required name field on create', function () {
        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates unique name on create', function () {
        ServiceCategory::firstOrCreate(
            ['slug' => 'deck-staining'],
            ['name' => 'Deck Staining', 'icon' => 'paint-brush', 'color' => 'blue', 'sort_order' => 1]
        );

        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', 'Deck Staining')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    });

    it('validates icon must be from allowed list', function () {
        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', 'Test Category')
            ->set('icon', 'invalid-icon')
            ->call('save')
            ->assertHasErrors(['icon']);
    });

    it('validates color must be from allowed list', function () {
        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', 'Test Category')
            ->set('color', 'neon-green')
            ->call('save')
            ->assertHasErrors(['color']);
    });

    it('auto-generates slug from name on create', function () {
        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', 'Power Washing & Cleaning')
            ->set('icon', 'sparkles')
            ->set('color', 'blue')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('service_categories', [
            'name' => 'Power Washing & Cleaning',
            'slug' => 'power-washing-cleaning',
        ]);
    });

    it('assigns incremented sort order to new category', function () {
        ServiceCategory::firstOrCreate(
            ['slug' => 'first-cat'],
            ['name' => 'First Cat', 'icon' => 'wrench', 'color' => 'blue', 'sort_order' => 5]
        );

        Livewire::test('pages::settings.service-categories')
            ->call('openAddModal')
            ->set('name', 'Second Cat')
            ->set('icon', 'bolt')
            ->set('color', 'red')
            ->call('save')
            ->assertHasNoErrors();

        $secondCat = ServiceCategory::where('slug', 'second-cat')->first();
        expect($secondCat)->not->toBeNull();
        expect($secondCat->sort_order)->toBeGreaterThan(5);
    });
});

describe('Edit Service Category', function () {

    it('can edit an existing service category', function () {
        $category = ServiceCategory::factory()->create([
            'name' => 'Fence Work',
            'icon' => 'wrench',
            'color' => 'blue',
        ]);

        Livewire::test('pages::settings.service-categories')
            ->call('openEditModal', $category->id)
            ->assertSet('name', 'Fence Work')
            ->assertSet('icon', 'wrench')
            ->set('name', 'Fence Staining')
            ->set('color', 'purple')
            ->call('save')
            ->assertDispatched('service-categories-updated');

        $category->refresh();
        expect($category->name)->toBe('Fence Staining');
        expect($category->slug)->toBe('fence-staining');
        expect($category->color)->toBe('purple');
    });

    it('allows editing to keep the same name without unique violation', function () {
        $category = ServiceCategory::factory()->create(['name' => 'Carpentry']);

        Livewire::test('pages::settings.service-categories')
            ->call('openEditModal', $category->id)
            ->assertSet('name', 'Carpentry')
            ->set('description', 'Updated description')
            ->call('save')
            ->assertHasNoErrors();

        expect($category->fresh()->description)->toBe('Updated description');
    });

    it('loads existing values into the edit modal', function () {
        $category = ServiceCategory::factory()->create([
            'name' => 'Drywall Patching',
            'description' => 'Patch and repair drywall',
            'icon' => 'bolt',
            'color' => 'yellow',
            'is_active' => false,
        ]);

        Livewire::test('pages::settings.service-categories')
            ->call('openEditModal', $category->id)
            ->assertSet('editingId', $category->id)
            ->assertSet('name', 'Drywall Patching')
            ->assertSet('description', 'Patch and repair drywall')
            ->assertSet('icon', 'bolt')
            ->assertSet('color', 'yellow')
            ->assertSet('is_active', false);
    });
});

describe('Toggle Active/Inactive', function () {

    it('can toggle active to inactive', function () {
        $category = ServiceCategory::factory()->create(['is_active' => true]);

        Livewire::test('pages::settings.service-categories')
            ->call('toggleActive', $category->id);

        expect($category->fresh()->is_active)->toBeFalse();
    });

    it('can toggle inactive to active', function () {
        $category = ServiceCategory::factory()->create(['is_active' => false]);

        Livewire::test('pages::settings.service-categories')
            ->call('toggleActive', $category->id);

        expect($category->fresh()->is_active)->toBeTrue();
    });
});

describe('Delete Service Category', function () {

    it('can delete a category with no references', function () {
        $category = ServiceCategory::factory()->create(['name' => 'Deletable']);

        Livewire::test('pages::settings.service-categories')
            ->call('confirmDelete', $category->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deletingName', 'Deletable')
            ->call('delete')
            ->assertSet('showDeleteModal', false)
            ->assertDispatched('service-categories-updated');

        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    });

    it('prevents deleting a category with crew profile references', function () {
        $category = ServiceCategory::factory()->create();

        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['service_category_id' => $category->id]);

        Livewire::test('pages::settings.service-categories')
            ->call('confirmDelete', $category->id)
            ->assertSet('showDeleteModal', false);

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    });

    it('can cancel the delete modal', function () {
        $category = ServiceCategory::factory()->create();

        Livewire::test('pages::settings.service-categories')
            ->call('confirmDelete', $category->id)
            ->assertSet('showDeleteModal', true)
            ->call('cancelDelete')
            ->assertSet('showDeleteModal', false)
            ->assertSet('deletingId', null);

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    });
});

describe('Painting Category Display', function () {

    it('painting category shows in the list', function () {
        ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'sort_order' => 1, 'is_active' => true]
        );

        Livewire::test('pages::settings.service-categories')
            ->assertSee('Painting')
            ->assertSee('painting');
    });
});

describe('Sort Order', function () {

    it('categories are displayed in sort order', function () {
        ServiceCategory::query()->delete();

        $catC = ServiceCategory::factory()->create(['sort_order' => 30, 'name' => 'Cat C']);
        $catA = ServiceCategory::factory()->create(['sort_order' => 10, 'name' => 'Cat A']);
        $catB = ServiceCategory::factory()->create(['sort_order' => 20, 'name' => 'Cat B']);

        $component = Livewire::test('pages::settings.service-categories');

        $categories = $component->instance()->categories;
        expect($categories->first()->name)->toBe('Cat A');
        expect($categories->last()->name)->toBe('Cat C');
    });

    it('can reorder categories with moveDown', function () {
        $cat1 = ServiceCategory::factory()->create(['sort_order' => 10, 'name' => 'Alpha']);
        $cat2 = ServiceCategory::factory()->create(['sort_order' => 20, 'name' => 'Beta']);

        Livewire::test('pages::settings.service-categories')
            ->call('moveDown', $cat1->id);

        expect($cat1->fresh()->sort_order)->toBe(20);
        expect($cat2->fresh()->sort_order)->toBe(10);
    });

    it('can reorder categories with moveUp', function () {
        $cat1 = ServiceCategory::factory()->create(['sort_order' => 10, 'name' => 'Alpha']);
        $cat2 = ServiceCategory::factory()->create(['sort_order' => 20, 'name' => 'Beta']);

        Livewire::test('pages::settings.service-categories')
            ->call('moveUp', $cat2->id);

        expect($cat1->fresh()->sort_order)->toBe(20);
        expect($cat2->fresh()->sort_order)->toBe(10);
    });

    it('moveUp on first item does nothing', function () {
        ServiceCategory::query()->delete();

        $cat1 = ServiceCategory::factory()->create(['sort_order' => 10, 'name' => 'First']);
        $cat2 = ServiceCategory::factory()->create(['sort_order' => 20, 'name' => 'Second']);

        Livewire::test('pages::settings.service-categories')
            ->call('moveUp', $cat1->id);

        expect($cat1->fresh()->sort_order)->toBe(10);
        expect($cat2->fresh()->sort_order)->toBe(20);
    });

    it('moveDown on last item does nothing', function () {
        ServiceCategory::query()->delete();

        $cat1 = ServiceCategory::factory()->create(['sort_order' => 10, 'name' => 'First']);
        $cat2 = ServiceCategory::factory()->create(['sort_order' => 20, 'name' => 'Last']);

        Livewire::test('pages::settings.service-categories')
            ->call('moveDown', $cat2->id);

        expect($cat1->fresh()->sort_order)->toBe(10);
        expect($cat2->fresh()->sort_order)->toBe(20);
    });
});
