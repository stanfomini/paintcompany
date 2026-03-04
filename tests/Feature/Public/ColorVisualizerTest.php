<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('public visualizer page loads without auth', function () {
    $this->get(route('visualize'))->assertSuccessful();
});

it('shows popular colors on the page', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    PaintColor::factory()->forBrand($brand)->popular()->create(['name' => 'Alabaster']);

    $this->get(route('visualize'))->assertSee('Alabaster');
});

it('allows first 2 free visualizations', function () {
    Queue::fake();
    Storage::fake('photos');

    Livewire::test('public.color-visualizer')
        ->set('prompt', 'Paint the walls with Benjamin Moore White Dove')
        ->set('uploadedPhoto', UploadedFile::fake()->image('house.jpg'))
        ->call('generateVisualization')
        ->assertSet('isGenerating', true);

    Queue::assertPushed(GenerateColorVisualizationJob::class);
});

it('shows lead form after free limit reached', function () {
    Storage::fake('photos');

    // First, visit the page to establish a session
    $this->get(route('visualize'));
    $sessionId = session()->getId();

    // Seed visualizations for this session so limit is reached
    ColorVisualization::factory()->withSession($sessionId)->count(2)->create();

    Livewire::test('public.color-visualizer')
        ->set('prompt', 'Paint it white please test')
        ->set('uploadedPhoto', UploadedFile::fake()->image('house.jpg'))
        ->call('generateVisualization')
        ->assertSet('showLeadForm', true);
});

it('result page loads with valid completed uuid', function () {
    $viz = ColorVisualization::factory()->completed()->create();

    $this->get(route('visualize.result', $viz->uuid))->assertSuccessful();
});

it('result page returns 404 for invalid uuid', function () {
    $this->get(route('visualize.result', 'nonexistent-uuid'))->assertNotFound();
});

it('result page returns 404 for non-completed visualization', function () {
    $viz = ColorVisualization::factory()->create(); // pending status

    $this->get(route('visualize.result', $viz->uuid))->assertNotFound();
});

it('inserts color name into prompt', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    $color = PaintColor::factory()->forBrand($brand)->create(['name' => 'Iron Ore']);

    Livewire::test('public.color-visualizer')
        ->call('insertColorName', $color->id)
        ->assertSet('prompt', 'Sherwin-Williams Iron Ore');
});

it('appends color name to existing prompt', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Benjamin Moore']);
    $color = PaintColor::factory()->forBrand($brand)->create(['name' => 'White Dove']);

    Livewire::test('public.color-visualizer')
        ->set('prompt', 'Paint the walls')
        ->call('insertColorName', $color->id)
        ->assertSet('prompt', 'Paint the walls, Benjamin Moore White Dove');
});

it('submits lead form and creates customer and deal', function () {
    $internal = \App\Models\User::factory()->internal()->create();

    Livewire::test('public.color-visualizer')
        ->set('leadFirstName', 'John')
        ->set('leadLastName', 'Smith')
        ->set('leadEmail', 'john@example.com')
        ->set('leadPhone', '555-0100')
        ->set('leadAddress', '123 Main St')
        ->call('submitLeadForm')
        ->assertSet('leadSubmitted', true)
        ->assertSet('showLeadForm', false)
        ->assertDispatched('lead-captured');

    $this->assertDatabaseHas('customers', [
        'email' => 'john@example.com',
        'first_name' => 'John',
        'last_name' => 'Smith',
    ]);

    $this->assertDatabaseHas('deals', [
        'title' => 'Color Visualizer Lead - John Smith',
        'stage' => 'lead',
        'lead_source' => 'website',
    ]);
});

it('validates lead form requires name and email', function () {
    Livewire::test('public.color-visualizer')
        ->call('submitLeadForm')
        ->assertHasErrors(['leadFirstName', 'leadLastName', 'leadEmail']);
});

it('regenerate resets state for new visualization', function () {
    Queue::fake();
    Storage::fake('photos');

    $component = Livewire::test('public.color-visualizer')
        ->set('prompt', 'Paint the walls with Benjamin Moore White Dove')
        ->set('uploadedPhoto', UploadedFile::fake()->image('house.jpg'))
        ->call('generateVisualization')
        ->assertSet('isGenerating', true);

    $component->call('regenerate')
        ->assertSet('isGenerating', false)
        ->assertSet('visualizationId', null);
});

it('result page displays before and after images', function () {
    $viz = ColorVisualization::factory()->completed()->create();

    $this->get(route('visualize.result', $viz->uuid))
        ->assertSuccessful()
        ->assertSee('Before')
        ->assertSee('After');
});

it('result page shows parsed colors when available', function () {
    $viz = ColorVisualization::factory()->completed()->create([
        'parsed_colors' => [
            ['name' => 'White Dove', 'code' => 'OC-17', 'hex' => '#F3EFE0', 'brand' => 'Benjamin Moore'],
        ],
    ]);

    $this->get(route('visualize.result', $viz->uuid))
        ->assertSuccessful()
        ->assertSee('White Dove')
        ->assertSee('Benjamin Moore');
});
