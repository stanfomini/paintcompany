<?php

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Livewire\Livewire;

describe('Photo Gallery component', function () {
    it('renders for internal user on property page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertStatus(200)
            ->assertSee('Photos');
    });

    it('shows photos in grid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $photo1 = PropertyPhoto::factory()->forProperty($property)->create(['caption' => 'Front yard']);
        $photo2 = PropertyPhoto::factory()->forProperty($property)->create(['caption' => 'Back yard']);

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertSee('Front yard')
            ->assertSee('Back yard');
    });

    it('filters by category', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        PropertyPhoto::factory()->forProperty($property)->before()->create(['caption' => 'Before shot']);
        PropertyPhoto::factory()->forProperty($property)->after()->create(['caption' => 'After shot']);

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertSee('Before shot')
            ->assertSee('After shot')
            ->call('setCategoryFilter', 'before')
            ->assertSee('Before shot')
            ->assertDontSee('After shot');
    });

    it('allows internal user to delete photos', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->call('deletePhoto', $photo->id);

        expect(PropertyPhoto::find($photo->id))->toBeNull();
    });

    it('opens lightbox when clicking a photo', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->call('openLightbox', $photo->id)
            ->assertSet('selectedPhotoId', $photo->id);
    });

    it('closes lightbox', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->call('openLightbox', $photo->id)
            ->assertSet('selectedPhotoId', $photo->id)
            ->call('closeLightbox')
            ->assertSet('selectedPhotoId', null);
    });

    it('shows upload button for users with permission', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertSet('canUpload', true)
            ->assertSee('Upload Photos');
    });

    it('hides upload button for users without permission', function () {
        $user = User::factory()->create(['type' => 'customer']);
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertSet('canUpload', false)
            ->assertDontSee('Upload Photos');
    });

    it('shows empty state when no photos exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property])
            ->assertSee('No photos yet');
    });

    it('toggles proposal flag on a photo', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->forEstimate($estimate)->create([
            'show_on_proposal' => false,
        ]);

        Livewire::actingAs($user)
            ->test('shared.photo-gallery', [
                'property' => $property,
                'estimate' => $estimate,
            ])
            ->call('toggleProposal', $photo->id);

        expect($photo->fresh()->show_on_proposal)->toBeTrue();
    });

    it('shows category counts', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        PropertyPhoto::factory()->forProperty($property)->before()->count(2)->create();
        PropertyPhoto::factory()->forProperty($property)->after()->count(3)->create();

        $component = Livewire::actingAs($user)
            ->test('shared.photo-gallery', ['property' => $property]);

        $component->assertSee('Before (2)')
            ->assertSee('After (3)');
    });
});
