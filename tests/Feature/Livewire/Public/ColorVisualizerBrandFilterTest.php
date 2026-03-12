<?php

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Livewire\Livewire;

beforeEach(function () {
    $this->sw = PaintBrand::factory()->sherwinWilliams()->create();
    $this->bm = PaintBrand::factory()->benjaminMoore()->create();

    // SW popular colors (avoid names that appear in placeholder/tip text)
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Accessible Beige', 'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Tricorn Black', 'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Repose Gray', 'intended_use' => 'both',
    ]);

    // BM popular colors
    PaintColor::factory()->forBrand($this->bm)->popular()->create([
        'name' => 'Chantilly Lace', 'intended_use' => 'interior',
    ]);
});

it('renders brand selector on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->assertSee($this->sw->name)
        ->assertSee($this->bm->name);
});

it('shows no popular colors until brand is selected on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->assertSee('Select a brand to see popular colors')
        ->assertDontSee('Accessible Beige');
});

it('shows interior popular colors for selected brand on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSee('Accessible Beige')  // interior
        ->assertSee('Repose Gray')       // both
        ->assertDontSee('Chantilly Lace'); // wrong brand
});

it('shows exterior popular colors for selected brand on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSee('Tricorn Black')     // exterior
        ->assertSee('Repose Gray');      // both (shows in both rows)
});

it('toggles brand off when clicking same brand again on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSet('selectedBrandId', $this->sw->id)
        ->call('selectBrand', $this->sw->id)
        ->assertSet('selectedBrandId', null);
});

it('filters search by brand on public visualizer', function () {
    PaintColor::factory()->forBrand($this->sw)->create(['name' => 'Snowbound']);
    PaintColor::factory()->forBrand($this->bm)->create(['name' => 'Snow White']);

    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->set('colorSearch', 'Snow')
        ->assertSee('Snowbound')
        ->assertDontSee('Snow White');
});

it('searches all brands when no brand selected on public visualizer', function () {
    PaintColor::factory()->forBrand($this->sw)->create(['name' => 'Snowbound']);
    PaintColor::factory()->forBrand($this->bm)->create(['name' => 'Snow White']);

    Livewire::test('public.color-visualizer')
        ->set('colorSearch', 'Snow')
        ->assertSee('Snowbound')
        ->assertSee('Snow White');
});
