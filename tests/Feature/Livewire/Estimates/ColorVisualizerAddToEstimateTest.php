<?php

use App\Models\ColorVisualization;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->property = Property::factory()->create();
    $this->deal = Deal::factory()->forProperty($this->property)->create();
    $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
        'created_by' => $this->internalUser->id,
    ]);
});

it('adds completed visualization to estimate as property photo', function () {
    Storage::fake('photos');
    Storage::fake(config('filesystems.default'));

    Storage::disk('photos')->put('photos/visualizations/result.png', 'fake-image-data');

    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->forEstimate($this->estimate)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer', ['estimate' => $this->estimate])
        ->set('visualizationId', $viz->id)
        ->call('addToEstimate')
        ->assertSet('addedToEstimate', true);

    $photo = PropertyPhoto::where('estimate_id', $this->estimate->id)->latest('id')->first();

    expect($photo)->not->toBeNull()
        ->and($photo->property_id)->toBe($this->property->id)
        ->and($photo->category)->toBe('reference')
        ->and($photo->show_on_proposal)->toBeTrue()
        ->and($photo->caption)->toContain('White Dove')
        ->and($photo->uploaded_by)->toBe($this->internalUser->id)
        ->and($photo->disk)->toBe(config('filesystems.default'));

    Storage::disk(config('filesystems.default'))->assertExists($photo->path);
});

it('does not show add to estimate button without estimate context', function () {
    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->assertDontSee('Add to Estimate');
});

it('shows add to estimate button when estimate exists and visualization is complete', function () {
    Storage::fake('photos');
    Storage::disk('photos')->put('photos/visualizations/result.png', 'fake-image-data');

    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->forEstimate($this->estimate)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer', ['estimate' => $this->estimate])
        ->set('visualizationId', $viz->id)
        ->assertSee('Add to Estimate');
});

it('shows added confirmation after adding', function () {
    Storage::fake('photos');
    Storage::fake(config('filesystems.default'));
    Storage::disk('photos')->put('photos/visualizations/result.png', 'fake-image-data');

    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->forEstimate($this->estimate)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer', ['estimate' => $this->estimate])
        ->set('visualizationId', $viz->id)
        ->call('addToEstimate')
        ->assertSee('Added to Estimate')
        ->assertDontSee('Add to Estimate');
});

it('does not add when no estimate is set', function () {
    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->call('addToEstimate');

    expect(PropertyPhoto::count())->toBe(0);
});

it('resets added state on regenerate', function () {
    Storage::fake('photos');
    Storage::fake(config('filesystems.default'));
    Storage::disk('photos')->put('photos/visualizations/result.png', 'fake-image-data');

    $viz = ColorVisualization::factory()
        ->forUser($this->internalUser)
        ->forEstimate($this->estimate)
        ->completed()
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer', ['estimate' => $this->estimate])
        ->set('visualizationId', $viz->id)
        ->call('addToEstimate')
        ->assertSet('addedToEstimate', true)
        ->call('startFresh')
        ->assertSet('addedToEstimate', false);
});
