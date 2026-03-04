<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
});

it('renders for authenticated internal user', function () {
    $this->actingAs($this->internalUser)
        ->get(route('color-visualizer'))
        ->assertSuccessful()
        ->assertSeeLivewire('estimates.color-visualizer');
});

it('renders with estimate context', function () {
    $property = Property::factory()->create();
    $deal = Deal::factory()->forProperty($property)->create();
    $estimate = Estimate::factory()->forDeal($deal)->draft()->create([
        'created_by' => $this->internalUser->id,
    ]);

    $this->actingAs($this->internalUser)
        ->get(route('estimates.visualize', $estimate))
        ->assertSuccessful();
});

it('loads popular colors', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    PaintColor::factory()->forBrand($brand)->popular()->create(['name' => 'Alabaster']);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->assertSee('Alabaster');
});

it('generates visualization and dispatches job', function () {
    Queue::fake();
    Storage::fake('photos');

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('prompt', 'Benjamin Moore White Dove on the walls')
        ->set('uploadedPhoto', UploadedFile::fake()->image('house.jpg', 800, 600))
        ->call('generateVisualization')
        ->assertSet('isGenerating', true);

    Queue::assertPushed(GenerateColorVisualizationJob::class);
    expect(ColorVisualization::count())->toBe(1);
});

it('inserts color name into prompt', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Benjamin Moore']);
    $color = PaintColor::factory()->forBrand($brand)->create(['name' => 'White Dove']);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->call('insertColorName', $color->id)
        ->assertSet('prompt', 'Benjamin Moore White Dove');
});

it('appends color name to existing prompt', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Benjamin Moore']);
    $color = PaintColor::factory()->forBrand($brand)->create(['name' => 'White Dove']);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('prompt', 'Existing text')
        ->call('insertColorName', $color->id)
        ->assertSet('prompt', 'Existing text, Benjamin Moore White Dove');
});

it('rejects unauthenticated users', function () {
    $this->get(route('color-visualizer'))
        ->assertRedirect();
});

it('validates required fields before generating', function () {
    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->call('generateVisualization')
        ->assertHasErrors(['prompt', 'uploadedPhoto']);
});

it('resets state on regenerate', function () {
    $viz = ColorVisualization::factory()->forUser($this->internalUser)->completed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->set('isGenerating', false)
        ->call('regenerate')
        ->assertSet('visualizationId', null)
        ->assertSet('isGenerating', false);
});

it('checks status and stops polling on completion', function () {
    $viz = ColorVisualization::factory()->forUser($this->internalUser)->completed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->set('isGenerating', true)
        ->call('checkStatus')
        ->assertSet('isGenerating', false);
});

it('checks status and stops polling on failure', function () {
    $viz = ColorVisualization::factory()->forUser($this->internalUser)->failed()->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->set('isGenerating', true)
        ->call('checkStatus')
        ->assertSet('isGenerating', false);
});
