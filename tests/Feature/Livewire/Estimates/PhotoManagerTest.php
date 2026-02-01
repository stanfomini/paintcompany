<?php

use App\Livewire\Estimates\PhotoManager;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimatePhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('PhotoManager component', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->internalUser = User::factory()->internal()->create();
        $this->deal = Deal::factory()->create();
        $this->estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'created_by' => $this->internalUser->id,
        ]);
    });

    it('renders for internal user', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->assertStatus(200);
    });

    it('can upload photos', function () {
        $file = UploadedFile::fake()->image('test-photo.jpg', 800, 600);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openUploadModal')
            ->assertSet('showUploadModal', true)
            ->set('uploadedPhotos', [$file])
            ->set('defaultCategory', 'before')
            ->set('defaultShowOnProposal', true)
            ->call('uploadPhotos')
            ->assertHasNoErrors()
            ->assertSet('showUploadModal', false)
            ->assertDispatched('photos-updated');

        $photo = EstimatePhoto::where('estimate_id', $this->estimate->id)->first();
        expect($photo)->not->toBeNull();
        expect($photo->category)->toBe('before');
        expect($photo->show_on_proposal)->toBeTrue();
        expect($photo->uploaded_by)->toBe($this->internalUser->id);
    });

    it('can edit photo caption', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'caption' => 'Original Caption',
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openEditModal', $photo->id)
            ->assertSet('showEditModal', true)
            ->assertSet('photoCaption', 'Original Caption')
            ->set('photoCaption', 'Updated Caption')
            ->call('savePhoto')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false)
            ->assertDispatched('photos-updated');

        $photo->refresh();
        expect($photo->caption)->toBe('Updated Caption');
    });

    it('can change photo category', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'category' => 'other',
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openEditModal', $photo->id)
            ->set('photoCategory', 'before')
            ->call('savePhoto')
            ->assertHasNoErrors();

        $photo->refresh();
        expect($photo->category)->toBe('before');
    });

    it('can delete photo', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'uploaded_by' => $this->internalUser->id,
        ]);

        expect(EstimatePhoto::where('id', $photo->id)->exists())->toBeTrue();

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('deletePhoto', $photo->id)
            ->assertDispatched('photos-updated');

        expect(EstimatePhoto::where('id', $photo->id)->exists())->toBeFalse();
    });

    it('filters photos by category', function () {
        EstimatePhoto::factory()->forEstimate($this->estimate)->before()->create([
            'uploaded_by' => $this->internalUser->id,
        ]);
        EstimatePhoto::factory()->forEstimate($this->estimate)->during()->create([
            'uploaded_by' => $this->internalUser->id,
        ]);
        EstimatePhoto::factory()->forEstimate($this->estimate)->reference()->create([
            'uploaded_by' => $this->internalUser->id,
        ]);

        $component = Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate]);

        // Initially shows all
        expect($component->get('filterCategory'))->toBe('all');

        // Filter by 'before'
        $component->call('setFilterCategory', 'before');
        expect($component->get('filterCategory'))->toBe('before');

        $filteredPhotos = $component->viewData('filteredPhotos');
        expect($filteredPhotos)->toHaveCount(1);
        expect($filteredPhotos->first()->category)->toBe('before');
    });

    it('show_on_proposal toggle works', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'show_on_proposal' => true,
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openEditModal', $photo->id)
            ->assertSet('photoShowOnProposal', true)
            ->set('photoShowOnProposal', false)
            ->call('savePhoto')
            ->assertHasNoErrors();

        $photo->refresh();
        expect($photo->show_on_proposal)->toBeFalse();
    });

    it('validates photo upload requires at least one photo', function () {
        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openUploadModal')
            ->set('uploadedPhotos', [])
            ->call('uploadPhotos')
            ->assertHasErrors(['uploadedPhotos']);
    });

    it('validates photo file size limit', function () {
        // Create a large image file that exceeds 5MB limit
        $file = UploadedFile::fake()->image('large-photo.jpg')->size(6000);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openUploadModal')
            ->set('uploadedPhotos', [$file])
            ->call('uploadPhotos')
            ->assertHasErrors(['uploadedPhotos.0']);
    });

    it('validates photo category is required', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openEditModal', $photo->id)
            ->set('photoCategory', '')
            ->call('savePhoto')
            ->assertHasErrors(['photoCategory']);
    });

    it('can reorder photos', function () {
        $photo1 = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'sort_order' => 0,
            'uploaded_by' => $this->internalUser->id,
        ]);
        $photo2 = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'sort_order' => 1,
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('reorderPhotos', [$photo2->id, $photo1->id])
            ->assertDispatched('photos-updated');

        $photo1->refresh();
        $photo2->refresh();

        expect($photo2->sort_order)->toBe(0);
        expect($photo1->sort_order)->toBe(1);
    });

    it('cannot delete photo from another estimate', function () {
        $otherEstimate = Estimate::factory()->create();
        $otherPhoto = EstimatePhoto::factory()->forEstimate($otherEstimate)->create();

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('deletePhoto', $otherPhoto->id);

        expect(EstimatePhoto::where('id', $otherPhoto->id)->exists())->toBeTrue();
    });

    it('closes upload modal and resets state', function () {
        $file = UploadedFile::fake()->image('test-photo.jpg');

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openUploadModal')
            ->set('uploadedPhotos', [$file])
            ->set('defaultCategory', 'before')
            ->call('closeUploadModal')
            ->assertSet('showUploadModal', false)
            ->assertSet('uploadedPhotos', [])
            ->assertSet('defaultCategory', 'other');
    });

    it('closes edit modal and resets state', function () {
        $photo = EstimatePhoto::factory()->forEstimate($this->estimate)->create([
            'caption' => 'Test Caption',
            'uploaded_by' => $this->internalUser->id,
        ]);

        Livewire::actingAs($this->internalUser)
            ->test(PhotoManager::class, ['estimate' => $this->estimate])
            ->call('openEditModal', $photo->id)
            ->assertSet('editingPhotoId', $photo->id)
            ->call('closeEditModal')
            ->assertSet('showEditModal', false)
            ->assertSet('editingPhotoId', null)
            ->assertSet('photoCaption', '');
    });
});
