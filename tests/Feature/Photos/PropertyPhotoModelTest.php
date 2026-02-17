<?php

use App\Models\Estimate;
use App\Models\Job;
use App\Models\PhotoAnnotation;
use App\Models\PhotoComment;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;

describe('PropertyPhoto model', function () {
    it('belongs to a property', function () {
        $property = Property::factory()->create();
        $photo = PropertyPhoto::factory()->forProperty($property)->create();

        expect($photo->property)->toBeInstanceOf(Property::class)
            ->and($photo->property->id)->toBe($property->id);
    });

    it('belongs to an estimate when set', function () {
        $estimate = Estimate::factory()->create();
        $photo = PropertyPhoto::factory()->forEstimate($estimate)->create();

        expect($photo->estimate)->toBeInstanceOf(Estimate::class)
            ->and($photo->estimate->id)->toBe($estimate->id);
    });

    it('belongs to a job when set', function () {
        $job = Job::factory()->create();
        $photo = PropertyPhoto::factory()->forJob($job)->create();

        expect($photo->job)->toBeInstanceOf(Job::class)
            ->and($photo->job->id)->toBe($job->id);
    });

    it('has an annotation', function () {
        $photo = PropertyPhoto::factory()->create();
        $annotation = PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
        ]);

        expect($photo->fresh()->annotation)->toBeInstanceOf(PhotoAnnotation::class)
            ->and($photo->fresh()->annotation->id)->toBe($annotation->id);
    });

    it('has comments', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoComment::factory()->count(3)->create([
            'property_photo_id' => $photo->id,
        ]);

        expect($photo->fresh()->comments)->toHaveCount(3);
    });

    it('scopes for estimate', function () {
        $estimate = Estimate::factory()->create();
        PropertyPhoto::factory()->forEstimate($estimate)->count(2)->create();
        PropertyPhoto::factory()->create(); // unrelated photo

        $photos = PropertyPhoto::forEstimate($estimate->id)->get();

        expect($photos)->toHaveCount(2)
            ->each(fn ($photo) => $photo->estimate_id->toBe($estimate->id));
    });

    it('scopes for proposal photos', function () {
        PropertyPhoto::factory()->proposalPhoto()->count(2)->create();
        PropertyPhoto::factory()->hiddenFromProposal()->count(3)->create();

        $proposalPhotos = PropertyPhoto::forProposal()->get();

        expect($proposalPhotos)->toHaveCount(2)
            ->each(fn ($photo) => $photo->show_on_proposal->toBeTrue());
    });

    it('scopes for job', function () {
        $job = Job::factory()->create();
        PropertyPhoto::factory()->forJob($job)->count(2)->create();
        PropertyPhoto::factory()->create(); // unrelated

        $photos = PropertyPhoto::forJob($job->id)->get();

        expect($photos)->toHaveCount(2)
            ->each(fn ($photo) => $photo->job_id->toBe($job->id));
    });

    it('returns category label', function () {
        $photo = PropertyPhoto::factory()->before()->create();

        expect($photo->category_label)->toBe('Before');
    });

    it('returns category label for each category', function () {
        foreach (PropertyPhoto::CATEGORIES as $key => $label) {
            $photo = PropertyPhoto::factory()->create(['category' => $key]);

            expect($photo->category_label)->toBe($label);
        }
    });

    it('has url accessor', function () {
        $photo = PropertyPhoto::factory()->create([
            'path' => 'property-photos/test-image.jpg',
            'disk' => 'public',
        ]);

        expect($photo->url)->toBeString()
            ->and($photo->url)->toContain('property-photos/test-image.jpg');
    });

    it('belongs to an uploader', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['uploaded_by' => $user->id]);

        expect($photo->uploadedBy)->toBeInstanceOf(User::class)
            ->and($photo->uploadedBy->id)->toBe($user->id);
    });

    it('casts sort_order as integer', function () {
        $photo = PropertyPhoto::factory()->create(['sort_order' => 5]);

        expect($photo->sort_order)->toBeInt()->toBe(5);
    });

    it('casts show_on_proposal as boolean', function () {
        $photo = PropertyPhoto::factory()->proposalPhoto()->create();

        expect($photo->show_on_proposal)->toBeBool()->toBeTrue();
    });

    it('scopes by category', function () {
        PropertyPhoto::factory()->before()->count(2)->create();
        PropertyPhoto::factory()->during()->count(1)->create();
        PropertyPhoto::factory()->after()->count(3)->create();
        PropertyPhoto::factory()->reference()->count(1)->create();

        expect(PropertyPhoto::before()->count())->toBe(2)
            ->and(PropertyPhoto::during()->count())->toBe(1)
            ->and(PropertyPhoto::after()->count())->toBe(3)
            ->and(PropertyPhoto::reference()->count())->toBe(1)
            ->and(PropertyPhoto::category('before')->count())->toBe(2);
    });

    it('deletes storage files when model is deleted', function () {
        \Illuminate\Support\Facades\Storage::fake();

        $path = 'property-photos/test.jpg';
        $thumbPath = 'property-photos/thumbs/test.jpg';

        \Illuminate\Support\Facades\Storage::put($path, 'image-data');
        \Illuminate\Support\Facades\Storage::put($thumbPath, 'thumb-data');

        $photo = PropertyPhoto::factory()->create([
            'path' => $path,
            'thumbnail_path' => $thumbPath,
            'disk' => config('filesystems.default'),
        ]);

        $photo->delete();

        \Illuminate\Support\Facades\Storage::assertMissing($path);
        \Illuminate\Support\Facades\Storage::assertMissing($thumbPath);
    });
});
