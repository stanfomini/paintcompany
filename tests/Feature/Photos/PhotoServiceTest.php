<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\PhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('PhotoService', function () {
    beforeEach(function () {
        Storage::fake();
        Queue::fake();
    });

    it('uploads a photo to a property', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $file = UploadedFile::fake()->image('test-photo.jpg', 800, 600);

        $service = app(PhotoService::class);
        $photo = $service->upload($file, $property, $user, [
            'category' => 'before',
            'caption' => 'Front of house',
            'show_on_proposal' => true,
        ]);

        expect($photo)->toBeInstanceOf(PropertyPhoto::class)
            ->and($photo->property_id)->toBe($property->id)
            ->and($photo->uploaded_by)->toBe($user->id)
            ->and($photo->category)->toBe('before')
            ->and($photo->caption)->toBe('Front of house')
            ->and($photo->show_on_proposal)->toBeTrue()
            ->and($photo->original_filename)->toBe('test-photo.jpg')
            ->and($photo->mime_type)->toBe('image/jpeg')
            ->and($photo->processing_status)->toBe('pending')
            ->and($photo->sort_order)->toBe(1);

        Storage::assertExists($photo->path);
    });

    it('deletes a photo and its storage files', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $file = UploadedFile::fake()->image('delete-me.jpg', 400, 300);

        $service = app(PhotoService::class);
        $photo = $service->upload($file, $property, $user);
        $path = $photo->path;

        Storage::assertExists($path);

        $service->delete($photo);

        Storage::assertMissing($path);
        expect(PropertyPhoto::find($photo->id))->toBeNull();
    });

    it('allows internal users to upload', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $service = app(PhotoService::class);

        expect($service->canUpload($user, $property))->toBeTrue();
    });

    it('allows crew to upload any category', function () {
        $crewUser = User::factory()->create(['type' => 'crew']);
        $property = Property::factory()->create();

        $service = app(PhotoService::class);

        expect($service->canUpload($crewUser, $property, 'before'))->toBeTrue()
            ->and($service->canUpload($crewUser, $property, 'during'))->toBeTrue()
            ->and($service->canUpload($crewUser, $property, 'after'))->toBeTrue()
            ->and($service->canUpload($crewUser, $property, 'general'))->toBeTrue();
    });

    it('allows customers to upload any category for their own property', function () {
        $customer = Customer::factory()->withPortalAccess()->create();
        $customerUser = $customer->user;
        $property = Property::factory()->forCustomer($customer)->create();

        $service = app(PhotoService::class);

        expect($service->canUpload($customerUser, $property, 'before'))->toBeTrue()
            ->and($service->canUpload($customerUser, $property, 'during'))->toBeTrue()
            ->and($service->canUpload($customerUser, $property, 'after'))->toBeTrue()
            ->and($service->canUpload($customerUser, $property, 'general'))->toBeTrue();
    });

    it('prevents customers from uploading to other properties', function () {
        $customer = Customer::factory()->withPortalAccess()->create();
        $customerUser = $customer->user;
        $otherProperty = Property::factory()->create();

        $service = app(PhotoService::class);

        expect($service->canUpload($customerUser, $otherProperty, 'before'))->toBeFalse();
    });

    it('returns visible photos for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        PropertyPhoto::factory()->forProperty($property)->count(3)->create();
        PropertyPhoto::factory()->forProperty($property)->proposalPhoto()->create();

        $service = app(PhotoService::class);
        $photos = $service->getVisiblePhotos($user, $property);

        expect($photos)->toHaveCount(4);
    });

    it('returns only proposal photos via forProposal scope', function () {
        $property = Property::factory()->create();

        PropertyPhoto::factory()->forProperty($property)->count(3)->create(['show_on_proposal' => false]);
        PropertyPhoto::factory()->forProperty($property)->proposalPhoto()->count(2)->create();

        $proposalPhotos = PropertyPhoto::where('property_id', $property->id)
            ->forProposal()
            ->get();

        expect($proposalPhotos)->toHaveCount(2)
            ->each(fn ($photo) => $photo->show_on_proposal->toBeTrue());
    });

    it('reorders photos', function () {
        $property = Property::factory()->create();

        $photo1 = PropertyPhoto::factory()->forProperty($property)->create(['sort_order' => 0]);
        $photo2 = PropertyPhoto::factory()->forProperty($property)->create(['sort_order' => 1]);
        $photo3 = PropertyPhoto::factory()->forProperty($property)->create(['sort_order' => 2]);

        $service = app(PhotoService::class);
        $service->reorder($property, [$photo3->id, $photo1->id, $photo2->id]);

        expect($photo3->fresh()->sort_order)->toBe(0)
            ->and($photo1->fresh()->sort_order)->toBe(1)
            ->and($photo2->fresh()->sort_order)->toBe(2);
    });

    it('increments sort order for each new upload', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $service = app(PhotoService::class);

        $photo1 = $service->upload(UploadedFile::fake()->image('a.jpg'), $property, $user);
        $photo2 = $service->upload(UploadedFile::fake()->image('b.jpg'), $property, $user);
        $photo3 = $service->upload(UploadedFile::fake()->image('c.jpg'), $property, $user);

        expect($photo1->sort_order)->toBe(1)
            ->and($photo2->sort_order)->toBe(2)
            ->and($photo3->sort_order)->toBe(3);
    });

    it('uploads multiple photos at once', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $files = [
            UploadedFile::fake()->image('one.jpg'),
            UploadedFile::fake()->image('two.jpg'),
            UploadedFile::fake()->image('three.jpg'),
        ];

        $service = app(PhotoService::class);
        $photos = $service->uploadMultiple($files, $property, $user, ['category' => 'before']);

        expect($photos)->toHaveCount(3)
            ->each(fn ($photo) => $photo->category->toBe('before'));
    });

    it('allows owner of photo to delete it', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->create(['uploaded_by' => $user->id]);

        $service = app(PhotoService::class);

        expect($service->canDelete($user, $photo))->toBeTrue();
    });

    it('prevents non-owner without permission from deleting photo', function () {
        $crewUser = User::factory()->create(['type' => 'crew']);
        $photo = PropertyPhoto::factory()->create();

        $service = app(PhotoService::class);

        expect($service->canDelete($crewUser, $photo))->toBeFalse();
    });
});
