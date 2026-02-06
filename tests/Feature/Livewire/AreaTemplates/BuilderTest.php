<?php

use App\Models\AreaTemplateSurface;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->withFullAccess()->create();

    // Create a default category for area templates
    $this->category = EstimateTemplateCategory::factory()->create([
        'name' => 'Rooms',
        'type' => 'room',
        'is_active' => true,
    ]);
});

it('renders the builder component for creating', function () {
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->assertOk()
        ->assertSee('Create Area Template')
        ->assertSet('isEditing', false);
});

it('initializes with default dimensions', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder');

    expect($component->get('roomLength'))->toBe(12.0);
    expect($component->get('roomWidth'))->toBe(12.0);
    expect($component->get('roomHeight'))->toBe(9.0);
});

it('initializes with default surfaces', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder');

    // Component initializes with 3 default surfaces: Walls, Ceiling, Baseboards
    expect($component->get('surfaces'))->toHaveCount(3);
    expect($component->get('surfaces')[0]['name'])->toBe('Walls');
    expect($component->get('surfaces')[0]['surface_type'])->toBe('walls');
    expect($component->get('surfaces')[1]['name'])->toBe('Ceiling');
    expect($component->get('surfaces')[1]['surface_type'])->toBe('ceiling');
    expect($component->get('surfaces')[2]['name'])->toBe('Baseboards');
    expect($component->get('surfaces')[2]['surface_type'])->toBe('trim');
});

it('computes wall square feet from dimensions', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('roomLength', 12)
        ->set('roomWidth', 14)
        ->set('roomHeight', 9);

    // Wall sqft = 2 × (L + W) × H = 2 × (12 + 14) × 9 = 468
    // Use getSurfaceQuantity to test the calculation
    $instance = $component->instance();
    $surface = ['quantity_source' => 'wall_sqft', 'quantity_override' => null];
    expect($instance->getSurfaceQuantity($surface))->toBe(468.0);
});

it('computes ceiling square feet from dimensions', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('roomLength', 12)
        ->set('roomWidth', 14)
        ->set('roomHeight', 9);

    // Ceiling sqft = L × W = 12 × 14 = 168
    $instance = $component->instance();
    $surface = ['quantity_source' => 'ceiling_sqft', 'quantity_override' => null];
    expect($instance->getSurfaceQuantity($surface))->toBe(168.0);
});

it('computes perimeter linear feet from dimensions', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('roomLength', 12)
        ->set('roomWidth', 14)
        ->set('roomHeight', 9);

    // Perimeter = 2 × (L + W) = 2 × (12 + 14) = 52
    $instance = $component->instance();
    $surface = ['quantity_source' => 'perimeter_lnft', 'quantity_override' => null];
    expect($instance->getSurfaceQuantity($surface))->toBe(52.0);
});

it('updates computed quantities when dimensions change', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('roomLength', 10)
        ->set('roomWidth', 10)
        ->set('roomHeight', 8);

    // Initial: Wall = 2 × (10 + 10) × 8 = 320, Ceiling = 100, Perimeter = 40
    $instance = $component->instance();

    $wallSurface = ['quantity_source' => 'wall_sqft', 'quantity_override' => null];
    $ceilingSurface = ['quantity_source' => 'ceiling_sqft', 'quantity_override' => null];
    $perimeterSurface = ['quantity_source' => 'perimeter_lnft', 'quantity_override' => null];

    expect($instance->getSurfaceQuantity($wallSurface))->toBe(320.0);
    expect($instance->getSurfaceQuantity($ceilingSurface))->toBe(100.0);
    expect($instance->getSurfaceQuantity($perimeterSurface))->toBe(40.0);
});

it('handles exterior single side mode when length is zero', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('scopeType', 'exterior')
        ->set('roomLength', 0)
        ->set('roomWidth', 40)
        ->set('roomHeight', 10);

    // Exterior single side: Wall = W × H = 40 × 10 = 400
    // Ceiling = 0 (no ceiling for single side)
    // Perimeter = W only = 40
    $instance = $component->instance();

    $wallSurface = ['quantity_source' => 'wall_sqft', 'quantity_override' => null];
    $ceilingSurface = ['quantity_source' => 'ceiling_sqft', 'quantity_override' => null];
    $perimeterSurface = ['quantity_source' => 'perimeter_lnft', 'quantity_override' => null];

    expect($instance->getSurfaceQuantity($wallSurface))->toBe(400.0);
    expect($instance->getSurfaceQuantity($ceilingSurface))->toBe(0.0);
    expect($instance->getSurfaceQuantity($perimeterSurface))->toBe(40.0);
});

it('resets surface categories when switching to exterior scope', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder');

    // Initially interior with interior categories
    expect($component->get('scopeType'))->toBe('interior');
    expect($component->get('surfaces.0.surface_category'))->toBe('walls');

    // Switch to exterior
    $component->set('scopeType', 'exterior');

    // Surface category should be reset to valid exterior category
    $surfaceCategory = $component->get('surfaces.0.surface_category');
    $validExteriorCategories = ['prep', 'body', 'eaves', 'fascia', 'windows', 'trim', 'exterior_doors', 'wood_replacement'];

    expect(in_array($surfaceCategory, $validExteriorCategories))->toBeTrue();
});

it('can add a surface', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('addSurface');

    // Starts with 3 surfaces, adds 1 = 4 total
    expect($component->get('surfaces'))->toHaveCount(4);
});

it('can remove a surface', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('removeSurface', 0);

    // Starts with 3 surfaces, removes 1 = 2 remaining
    expect($component->get('surfaces'))->toHaveCount(2);
});

it('cannot remove the last surface', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('removeSurface', 0)
        ->call('removeSurface', 0);

    // Should have 1 remaining (started with 3, removed 2)
    expect($component->get('surfaces'))->toHaveCount(1);

    // Try to remove the last one
    $component->call('removeSurface', 0);

    // Should still have one surface
    expect($component->get('surfaces'))->toHaveCount(1);
});

it('can reorder surfaces', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('surfaces.0.name', 'First Surface')
        ->call('addSurface')
        ->set('surfaces.1.name', 'Second Surface');

    expect($component->get('surfaces')[0]['name'])->toBe('First Surface');
    expect($component->get('surfaces')[1]['name'])->toBe('Second Surface');

    $component->call('moveSurfaceDown', 0);

    expect($component->get('surfaces')[0]['name'])->toBe('Second Surface');
    expect($component->get('surfaces')[1]['name'])->toBe('First Surface');

    $component->call('moveSurfaceUp', 1);

    expect($component->get('surfaces')[0]['name'])->toBe('First Surface');
    expect($component->get('surfaces')[1]['name'])->toBe('Second Surface');
});

it('can add nested options', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('addNestedOption');

    expect($component->get('nestedOptions'))->toHaveCount(1);
    expect($component->get('nestedOptions')[0]['nested_area_template_id'])->toBeNull();
});

it('can remove nested options', function () {
    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('addNestedOption')
        ->call('addNestedOption')
        ->call('removeNestedOption', 0);

    expect($component->get('nestedOptions'))->toHaveCount(1);
});

it('validates required fields on save', function () {
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('validates surface names on save', function () {
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('name', 'Test Template')
        ->set('surfaces.0.name', '')
        ->call('save')
        ->assertHasErrors(['surfaces.0.name']);
});

it('loads template data in edit mode', function () {
    $template = EstimateTemplate::factory()->create([
        'name' => 'Test Template',
        'description' => 'Test Description',
        'template_category_id' => $this->category->id,
        'scope_type' => 'interior',
        'visibility' => 'company',
        'is_active' => true,
        'is_configurable' => true,
        'default_coats' => 2,
        'metadata' => [
            'dimensions' => [
                'length' => 14,
                'width' => 12,
                'height' => 10,
            ],
        ],
        'created_by' => $this->user->id,
    ]);

    // Add surfaces to the template
    AreaTemplateSurface::factory()->create([
        'estimate_template_id' => $template->id,
        'name' => 'Walls',
        'surface_type' => 'walls',
        'calculation_method' => 'ratio',
        'calculation_value' => 1.0,
        'unit_type' => 'sqft',
        'default_coats' => 2,
        'is_required' => true,
        'is_default_enabled' => true,
        'sort_order' => 0,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test('area-templates.builder', ['template' => $template]);

    expect($component->get('isEditing'))->toBeTrue();
    expect($component->get('name'))->toBe('Test Template');
    expect($component->get('description'))->toBe('Test Description');
    expect($component->get('scopeType'))->toBe('interior');
    expect($component->get('roomLength'))->toBe(14.0);
    expect($component->get('roomWidth'))->toBe(12.0);
    expect($component->get('roomHeight'))->toBe(10.0);
    expect($component->get('surfaces'))->toHaveCount(1);
});

it('can create a new template', function () {
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('name', 'New Room Template')
        ->set('description', 'A test room')
        ->set('templateCategoryId', $this->category->id)
        ->set('scopeType', 'interior')
        ->set('visibility', 'company')
        ->set('defaultCoats', 2)
        ->set('surfaces.0.name', 'Walls')
        ->set('surfaces.0.surface_type', 'walls')
        ->set('surfaces.0.calculation_method', 'ratio')
        ->set('surfaces.0.calculation_value', 1.0)
        ->set('surfaces.0.unit_type', 'sqft')
        ->set('surfaces.0.default_coats', 2)
        ->call('save')
        ->assertRedirect();

    expect(EstimateTemplate::where('name', 'New Room Template')->exists())->toBeTrue();
});

it('can update an existing template', function () {
    $template = EstimateTemplate::factory()->create([
        'name' => 'Original Name',
        'template_category_id' => $this->category->id,
        'is_configurable' => true,
        'visibility' => 'company',
        'created_by' => $this->user->id,
        'metadata' => [
            'sizes' => ['M' => ['label' => 'Medium', 'sqft' => 200]],
            'default_size' => 'M',
        ],
    ]);

    AreaTemplateSurface::factory()->create([
        'estimate_template_id' => $template->id,
        'name' => 'Walls',
        'surface_type' => 'walls',
    ]);

    Livewire::actingAs($this->user)
        ->test('area-templates.builder', ['template' => $template])
        ->set('name', 'Updated Name')
        ->call('save')
        ->assertRedirect();

    $template->refresh();
    expect($template->name)->toBe('Updated Name');
});

it('cannot modify system templates', function () {
    $template = EstimateTemplate::factory()->create([
        'name' => 'System Template',
        'template_category_id' => $this->category->id,
        'visibility' => 'system',
        'is_configurable' => true,
        'created_by' => $this->user->id,
        'metadata' => [
            'sizes' => ['M' => ['label' => 'Medium', 'sqft' => 200]],
            'default_size' => 'M',
        ],
    ]);

    AreaTemplateSurface::factory()->create([
        'estimate_template_id' => $template->id,
        'name' => 'Walls',
        'surface_type' => 'walls',
    ]);

    // When trying to save a system template, the save method should flash an error
    // and NOT redirect (since it fails)
    Livewire::actingAs($this->user)
        ->test('area-templates.builder', ['template' => $template])
        ->set('name', 'Modified Name')
        ->call('save')
        ->assertNoRedirect();

    // Verify the template name was NOT changed
    $template->refresh();
    expect($template->name)->toBe('System Template');
});

it('shows available substrates filtered by scope', function () {
    Substrate::factory()->create([
        'name' => 'Interior Drywall',
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    Substrate::factory()->create([
        'name' => 'Exterior Wood',
        'scope_type' => 'exterior',
        'is_active' => true,
    ]);

    // Test that interior scope shows interior substrates
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('scopeType', 'interior')
        ->assertSee('Interior Drywall')
        ->assertDontSee('Exterior Wood');
});

it('shows available paint products filtered by scope', function () {
    PaintProduct::factory()->create([
        'name' => 'Interior Eggshell',
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    PaintProduct::factory()->create([
        'name' => 'Exterior Satin',
        'scope_type' => 'exterior',
        'is_active' => true,
    ]);

    // Test that interior scope shows interior products
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('scopeType', 'interior')
        ->assertSee('Interior Eggshell')
        ->assertDontSee('Exterior Satin');
});

it('shows live calculator section in sidebar', function () {
    $rate = ProductionRate::factory()->create([
        'name' => 'Wall Paint',
        'production_rate' => 200,
        'unit_type' => 'sqft',
        'is_active' => true,
        'scope' => 'interior',
        'surface_category' => 'walls',
    ]);

    // Test that the live calculator section renders with dimensions and coats
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->set('scopeType', 'interior')
        ->set('surfaces.0.name', 'Walls')
        ->set('surfaces.0.surface_category', 'walls')
        ->set('surfaces.0.production_rate_id', $rate->id)
        ->assertSee('Live Calculator')
        ->assertSee('Coats');
});

it('includes nested options in preview when default included', function () {
    $nestedTemplate = NestedAreaTemplate::factory()->create([
        'name' => 'Walk-in Closet',
        'fixed_price' => 150.00,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    // Test that nested option appears in preview
    Livewire::actingAs($this->user)
        ->test('area-templates.builder')
        ->call('addNestedOption')
        ->set('nestedOptions.0.nested_area_template_id', $nestedTemplate->id)
        ->set('nestedOptions.0.is_default_included', true)
        ->assertSee('Walk-in Closet')
        ->assertSee('$150.00');
});
