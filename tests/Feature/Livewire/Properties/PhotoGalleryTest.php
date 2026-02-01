<?php

use App\Livewire\Properties\PhotoGallery;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PhotoGallery component', function () {
    it('renders the photo gallery component', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->assertStatus(200);
    });

    it('displays existing photos', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');

        $photo = PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'caption' => 'Test photo caption',
            'category' => 'before',
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->assertSee('Test photo caption')
            ->assertSee('Before');
    });

    it('opens and closes upload modal', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->assertSet('showUploadModal', false)
            ->call('openUploadModal')
            ->assertSet('showUploadModal', true)
            ->call('closeUploadModal')
            ->assertSet('showUploadModal', false);
    });

    it('resets form fields when closing upload modal', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->set('caption', 'Test caption')
            ->set('category', 'after')
            ->call('closeUploadModal')
            ->assertSet('caption', '')
            ->assertSet('category', 'general');
    });

    it('validates photo is required for upload', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->call('uploadPhoto')
            ->assertHasErrors(['photo']);
    });

    it('validates photo must be an image', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->set('photo', UploadedFile::fake()->create('document.pdf', 100))
            ->call('uploadPhoto')
            ->assertHasErrors(['photo']);
    });

    it('validates category is valid', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->set('photo', UploadedFile::fake()->image('test.jpg'))
            ->set('category', 'invalid')
            ->call('uploadPhoto')
            ->assertHasErrors(['category']);
    });

    it('uploads photo successfully', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->set('photo', UploadedFile::fake()->image('test-photo.jpg'))
            ->set('caption', 'My test photo')
            ->set('category', 'before')
            ->call('uploadPhoto')
            ->assertHasNoErrors()
            ->assertDispatched('photo-uploaded')
            ->assertSet('showUploadModal', false);

        $this->assertDatabaseHas('property_photos', [
            'property_id' => $property->id,
            'uploaded_by' => $user->id,
            'caption' => 'My test photo',
            'category' => 'before',
        ]);
    });

    it('sets correct sort order for new photos', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');

        PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'sort_order' => 0,
        ]);

        PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->set('photo', UploadedFile::fake()->image('new.jpg'))
            ->call('uploadPhoto');

        $newPhoto = PropertyPhoto::where('property_id', $property->id)
            ->orderBy('id', 'desc')
            ->first();

        expect($newPhoto->sort_order)->toBe(2);
    });

    it('deletes photo successfully', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        Storage::fake('public');
        $path = 'property-photos/test.jpg';
        Storage::disk('public')->put($path, 'fake content');

        $photo = PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'path' => $path,
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->call('deletePhoto', $photo->id)
            ->assertDispatched('photo-deleted');

        $this->assertDatabaseMissing('property_photos', [
            'id' => $photo->id,
        ]);
    });

    it('cannot delete photo from different property', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();
        $otherProperty = Property::factory()->create();

        $photo = PropertyPhoto::factory()->create([
            'property_id' => $otherProperty->id,
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->call('deletePhoto', $photo->id)
            ->assertStatus(404);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    it('updates sort order', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        $photo1 = PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'sort_order' => 0,
        ]);

        $photo2 = PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'sort_order' => 1,
        ]);

        $photo3 = PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'sort_order' => 2,
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->call('updateSortOrder', [$photo3->id, $photo1->id, $photo2->id])
            ->assertDispatched('photos-reordered');

        expect(PropertyPhoto::find($photo3->id)->sort_order)->toBe(0);
        expect(PropertyPhoto::find($photo1->id)->sort_order)->toBe(1);
        expect(PropertyPhoto::find($photo2->id)->sort_order)->toBe(2);
    });

    it('displays photo count', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        PropertyPhoto::factory()->count(3)->create([
            'property_id' => $property->id,
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->assertSee('3 photos');
    });

    it('displays correct category badges', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'category' => 'before',
        ]);

        PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'category' => 'during',
        ]);

        PropertyPhoto::factory()->create([
            'property_id' => $property->id,
            'category' => 'after',
        ]);

        Livewire::actingAs($user)
            ->test(PhotoGallery::class, ['property' => $property])
            ->assertSee('Before')
            ->assertSee('During')
            ->assertSee('After');
    });
});
