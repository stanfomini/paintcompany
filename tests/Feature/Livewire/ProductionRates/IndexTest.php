<?php

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use Livewire\Livewire;

describe('ProductionRates Index component access', function () {
    it('internal user can access the page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('production-rates.index'))
            ->assertOk();
    });

    it('redirects agent users to their dashboard', function () {
        $user = User::factory()->agent()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('production-rates.index'))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects customer users to their dashboard', function () {
        $user = User::factory()->customer()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('production-rates.index'))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('unauthenticated users are redirected to login', function () {
        $this->get(route('production-rates.index'))
            ->assertRedirect(route('login'));
    });
});

describe('ProductionRates Index tab switching', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
    });

    it('starts with interior tab active', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->assertSet('activeTab', 'interior');
    });

    it('can switch to exterior tab', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('switchTab', 'exterior')
            ->assertSet('activeTab', 'exterior');
    });

    it('can switch to specialty tab', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('switchTab', 'specialty')
            ->assertSet('activeTab', 'specialty');
    });

    it('can switch back to interior tab', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('switchTab', 'exterior')
            ->call('switchTab', 'interior')
            ->assertSet('activeTab', 'interior');
    });
});

describe('ProductionRates Index category management', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
    });

    it('can create a category', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openCategoryModal')
            ->assertSet('showCategoryModal', true)
            ->set('categoryName', 'New Category')
            ->set('categoryDescription', 'Test description')
            ->call('saveCategory')
            ->assertSet('showCategoryModal', false);

        expect(ProductionRateCategory::where('name', 'New Category')->exists())->toBeTrue();
    });

    it('creates category with correct type based on active tab', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('switchTab', 'exterior')
            ->call('openCategoryModal')
            ->set('categoryName', 'Exterior Category')
            ->call('saveCategory');

        $category = ProductionRateCategory::where('name', 'Exterior Category')->first();
        expect($category->type)->toBe('exterior');
    });

    it('can edit a category', function () {
        $category = ProductionRateCategory::factory()->interior()->create([
            'name' => 'Original Name',
            'description' => 'Original description',
        ]);

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openCategoryModal', $category->id)
            ->assertSet('showCategoryModal', true)
            ->assertSet('categoryName', 'Original Name')
            ->assertSet('categoryDescription', 'Original description')
            ->set('categoryName', 'Updated Name')
            ->set('categoryDescription', 'Updated description')
            ->call('saveCategory');

        $category->refresh();
        expect($category->name)->toBe('Updated Name');
        expect($category->description)->toBe('Updated description');
    });

    it('can delete a category', function () {
        $category = ProductionRateCategory::factory()->interior()->create();
        $categoryId = $category->id;

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('confirmDelete', 'category', $category->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deleteType', 'category')
            ->assertSet('deleteId', $category->id)
            ->call('delete')
            ->assertSet('showDeleteModal', false);

        expect(ProductionRateCategory::find($categoryId))->toBeNull();
    });

    it('deleting category cascades to rates', function () {
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create();
        $rateId = $rate->id;

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('confirmDelete', 'category', $category->id)
            ->call('delete');

        expect(ProductionRate::find($rateId))->toBeNull();
    });

    it('validates category name is required', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openCategoryModal')
            ->set('categoryName', '')
            ->call('saveCategory')
            ->assertHasErrors(['categoryName' => 'required']);
    });

    it('validates category name max length', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openCategoryModal')
            ->set('categoryName', str_repeat('a', 256))
            ->call('saveCategory')
            ->assertHasErrors(['categoryName' => 'max']);
    });
});

describe('ProductionRates Index rate management', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
        $this->category = ProductionRateCategory::factory()->interior()->create();
    });

    it('can create a rate', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->assertSet('showRateModal', true)
            ->set('rateName', 'Test Rate')
            ->set('rateDescription', 'Test description')
            ->set('rateUnitType', 'sqft')
            ->set('rateProductionRate', '250')
            ->set('rateDefaultCoats', 2)
            ->call('saveRate')
            ->assertSet('showRateModal', false);

        $rate = ProductionRate::where('name', 'Test Rate')->first();
        expect($rate)->not->toBeNull();
        expect($rate->category_id)->toBe($this->category->id);
        expect((float) $rate->production_rate)->toBe(250.00);
    });

    it('can edit a rate', function () {
        $rate = ProductionRate::factory()->forCategory($this->category)->create([
            'name' => 'Original Rate',
            'production_rate' => 200.00,
        ]);

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', $rate->id)
            ->assertSet('showRateModal', true)
            ->assertSet('rateName', 'Original Rate')
            ->set('rateName', 'Updated Rate')
            ->set('rateProductionRate', '300')
            ->call('saveRate');

        $rate->refresh();
        expect($rate->name)->toBe('Updated Rate');
        expect((float) $rate->production_rate)->toBe(300.00);
    });

    it('can delete a rate', function () {
        $rate = ProductionRate::factory()->forCategory($this->category)->create();
        $rateId = $rate->id;

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('confirmDelete', 'rate', $rate->id)
            ->assertSet('showDeleteModal', true)
            ->assertSet('deleteType', 'rate')
            ->assertSet('deleteId', $rate->id)
            ->call('delete')
            ->assertSet('showDeleteModal', false);

        expect(ProductionRate::find($rateId))->toBeNull();
    });

    it('validates rate name is required', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', '')
            ->set('rateProductionRate', '250')
            ->call('saveRate')
            ->assertHasErrors(['rateName' => 'required']);
    });

    it('validates production rate is required', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', 'Test Rate')
            ->set('rateProductionRate', '')
            ->call('saveRate')
            ->assertHasErrors(['rateProductionRate' => 'required']);
    });

    it('validates production rate is numeric', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', 'Test Rate')
            ->set('rateProductionRate', 'not-a-number')
            ->call('saveRate')
            ->assertHasErrors(['rateProductionRate' => 'numeric']);
    });

    it('validates production rate is greater than zero', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', 'Test Rate')
            ->set('rateProductionRate', '0')
            ->call('saveRate')
            ->assertHasErrors(['rateProductionRate' => 'min']);
    });

    it('validates unit type is valid', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', 'Test Rate')
            ->set('rateUnitType', 'invalid')
            ->set('rateProductionRate', '250')
            ->call('saveRate')
            ->assertHasErrors(['rateUnitType' => 'in']);
    });

    it('validates category id exists', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal')
            ->set('rateName', 'Test Rate')
            ->set('rateProductionRate', '250')
            ->set('rateCategoryId', 99999)
            ->call('saveRate')
            ->assertHasErrors(['rateCategoryId' => 'exists']);
    });

    it('validates default coats is between 1 and 5', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', null, $this->category->id)
            ->set('rateName', 'Test Rate')
            ->set('rateProductionRate', '250')
            ->set('rateDefaultCoats', 10)
            ->call('saveRate')
            ->assertHasErrors(['rateDefaultCoats' => 'max']);
    });
});

describe('ProductionRates Index seed defaults', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
    });

    it('can seed default rates', function () {
        expect(ProductionRateCategory::count())->toBe(0);
        expect(ProductionRate::count())->toBe(0);

        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('seedDefaults');

        expect(ProductionRateCategory::count())->toBe(3);
        expect(ProductionRate::count())->toBe(10);
    });

    it('does not duplicate when seeding multiple times', function () {
        Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('seedDefaults')
            ->call('seedDefaults');

        expect(ProductionRateCategory::count())->toBe(3);
        expect(ProductionRate::count())->toBe(10);
    });
});

describe('ProductionRates Index categories computed property', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
    });

    it('returns categories for current active tab', function () {
        ProductionRateCategory::factory()->interior()->count(2)->create();
        ProductionRateCategory::factory()->exterior()->count(3)->create();

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index');

        expect($component->get('categories'))->toHaveCount(2);

        $component->call('switchTab', 'exterior');

        expect($component->get('categories'))->toHaveCount(3);
    });

    it('eager loads rates for categories', function () {
        $category = ProductionRateCategory::factory()->interior()->create();
        ProductionRate::factory()->forCategory($category)->count(3)->create();

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index');

        $categories = $component->get('categories');
        expect($categories->first()->relationLoaded('rates'))->toBeTrue();
        expect($categories->first()->rates)->toHaveCount(3);
    });

    it('orders rates by sort_order', function () {
        $category = ProductionRateCategory::factory()->interior()->create();
        ProductionRate::factory()->forCategory($category)->create(['name' => 'Third', 'sort_order' => 3]);
        ProductionRate::factory()->forCategory($category)->create(['name' => 'First', 'sort_order' => 1]);
        ProductionRate::factory()->forCategory($category)->create(['name' => 'Second', 'sort_order' => 2]);

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index');

        $rates = $component->get('categories')->first()->rates;
        expect($rates[0]->name)->toBe('First');
        expect($rates[1]->name)->toBe('Second');
        expect($rates[2]->name)->toBe('Third');
    });
});

describe('ProductionRates Index modal state management', function () {
    beforeEach(function () {
        $this->user = User::factory()->internal()->withFullAccess()->create();
    });

    it('resets category form when opening modal for new category', function () {
        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->set('categoryName', 'Previous Value')
            ->set('categoryDescription', 'Previous Description')
            ->call('openCategoryModal');

        expect($component->get('categoryName'))->toBe('');
        expect($component->get('categoryDescription'))->toBe('');
        expect($component->get('editingCategoryId'))->toBeNull();
    });

    it('loads category data when opening modal for editing', function () {
        $category = ProductionRateCategory::factory()->interior()->create([
            'name' => 'Test Category',
            'description' => 'Test Description',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openCategoryModal', $category->id);

        expect($component->get('categoryName'))->toBe('Test Category');
        expect($component->get('categoryDescription'))->toBe('Test Description');
        expect($component->get('editingCategoryId'))->toBe($category->id);
    });

    it('resets rate form when opening modal for new rate', function () {
        $category = ProductionRateCategory::factory()->interior()->create();

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->set('rateName', 'Previous Value')
            ->call('openRateModal', null, $category->id);

        expect($component->get('rateName'))->toBe('');
        expect($component->get('rateDescription'))->toBe('');
        expect($component->get('rateUnitType'))->toBe('sqft');
        expect($component->get('editingRateId'))->toBeNull();
        expect($component->get('rateCategoryId'))->toBe($category->id);
    });

    it('loads rate data when opening modal for editing', function () {
        $category = ProductionRateCategory::factory()->interior()->create();
        $rate = ProductionRate::factory()->forCategory($category)->create([
            'name' => 'Test Rate',
            'description' => 'Test Description',
            'unit_type' => 'lnft',
            'production_rate' => 150.00,
            'default_coats' => 3,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test('production-rates.index')
            ->call('openRateModal', $rate->id);

        expect($component->get('rateName'))->toBe('Test Rate');
        expect($component->get('rateDescription'))->toBe('Test Description');
        expect($component->get('rateUnitType'))->toBe('lnft');
        expect($component->get('rateProductionRate'))->toBe('150.00');
        expect($component->get('rateDefaultCoats'))->toBe(3);
        expect($component->get('editingRateId'))->toBe($rate->id);
        expect($component->get('rateCategoryId'))->toBe($category->id);
    });
});
