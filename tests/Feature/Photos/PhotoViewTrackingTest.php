<?php

use App\Models\Deal;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\PhotoView;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\AnnotationService;
use App\Services\PhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('PhotoView model', function () {
    it('marks a photo as viewed by a user', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create();

        $view = PhotoView::markViewed($photo, $user);

        expect($view)->toBeInstanceOf(PhotoView::class)
            ->and($view->property_photo_id)->toBe($photo->id)
            ->and($view->user_id)->toBe($user->id)
            ->and($view->viewed_at)->not->toBeNull();
    });

    it('upserts viewed_at on repeated views', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create();

        $first = PhotoView::markViewed($photo, $user);
        $firstTime = $first->viewed_at;

        // Simulate time passing
        $this->travel(5)->minutes();

        $second = PhotoView::markViewed($photo, $user);

        expect($second->id)->toBe($first->id)
            ->and($second->viewed_at->isAfter($firstTime))->toBeTrue()
            ->and(PhotoView::where('property_photo_id', $photo->id)->where('user_id', $user->id)->count())->toBe(1);
    });

    it('tracks separate views per user', function () {
        $photo = PropertyPhoto::factory()->create();
        $user1 = User::factory()->internal()->create();
        $user2 = User::factory()->create(['type' => 'crew']);

        PhotoView::markViewed($photo, $user1);
        PhotoView::markViewed($photo, $user2);

        expect(PhotoView::where('property_photo_id', $photo->id)->count())->toBe(2);
    });

    it('detects unviewed changes when user has never viewed', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()]);

        expect(PhotoView::hasUnviewedChanges($photo, $user))->toBeTrue();
    });

    it('detects no unviewed changes when content_updated_at is null', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => null]);

        expect(PhotoView::hasUnviewedChanges($photo, $user))->toBeFalse();
    });

    it('detects no unviewed changes when viewed after update', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()->subMinutes(5)]);

        PhotoView::markViewed($photo, $user);

        expect(PhotoView::hasUnviewedChanges($photo, $user))->toBeFalse();
    });

    it('detects unviewed changes when updated after view', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()->subMinutes(10)]);

        PhotoView::markViewed($photo, $user);

        // Content updated after the view - travel forward to ensure timestamp difference
        $this->travel(5)->minutes();
        $photo->update(['content_updated_at' => now()]);

        expect(PhotoView::hasUnviewedChanges($photo->fresh(), $user))->toBeTrue();
    });

    it('cascades on photo delete', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create();

        Storage::fake($photo->disk ?? 'public');

        PhotoView::markViewed($photo, $user);

        expect(PhotoView::where('property_photo_id', $photo->id)->count())->toBe(1);

        $photo->delete();

        expect(PhotoView::where('property_photo_id', $photo->id)->count())->toBe(0);
    });
});

describe('PropertyPhoto hasUnviewedChanges', function () {
    it('returns false for null user', function () {
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()]);
        $photo->load('views');

        expect($photo->hasUnviewedChanges(null))->toBeFalse();
    });

    it('returns false when no content_updated_at', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => null]);
        $photo->load('views');

        expect($photo->hasUnviewedChanges($user))->toBeFalse();
    });

    it('returns true when user has not viewed photo with updates', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()]);
        $photo->load('views');

        expect($photo->hasUnviewedChanges($user))->toBeTrue();
    });

    it('returns false when user viewed after last update', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => now()->subMinutes(5)]);
        PhotoView::markViewed($photo, $user);

        $photo = $photo->fresh()->load('views');

        expect($photo->hasUnviewedChanges($user))->toBeFalse();
    });
});

describe('Photo activity logging', function () {
    beforeEach(function () {
        Storage::fake();
        Queue::fake();
    });

    it('logs activity when photo is uploaded to a job', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $property = Property::factory()->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $service = app(PhotoService::class);
        $photo = $service->upload(
            UploadedFile::fake()->image('test.jpg'),
            $property,
            $user,
            ['job_id' => $job->id, 'category' => 'before']
        );

        $activity = JobActivity::where('job_id', $job->id)
            ->where('activity_type', 'photo_uploaded')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->description)->toContain($user->name)
            ->and($activity->description)->toContain('before')
            ->and($activity->metadata['photo_id'])->toBe($photo->id);
    });

    it('does not log activity when photo has no job', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $property = Property::factory()->create();

        $service = app(PhotoService::class);
        $service->upload(
            UploadedFile::fake()->image('test.jpg'),
            $property,
            $user
        );

        expect(JobActivity::where('activity_type', 'photo_uploaded')->count())->toBe(0);
    });

    it('logs activity when photo is annotated on a job', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $property = Property::factory()->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);
        $photo = PropertyPhoto::factory()->forProperty($property)->forJob($job)->create();

        $service = app(AnnotationService::class);
        $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $user);

        $activity = JobActivity::where('job_id', $job->id)
            ->where('activity_type', 'photo_annotated')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->description)->toContain($user->name)
            ->and($activity->description)->toContain('Team')
            ->and($activity->metadata['author_type'])->toBe('internal');
    });

    it('logs activity when photo is deleted from a job', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $property = Property::factory()->create();
        $deal = Deal::factory()->forProperty($property)->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);
        $photo = PropertyPhoto::factory()->forProperty($property)->forJob($job)->create([
            'category' => 'during',
        ]);

        $service = app(PhotoService::class);
        $service->delete($photo);

        $activity = JobActivity::where('job_id', $job->id)
            ->where('activity_type', 'photo_deleted')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->description)->toContain($user->name)
            ->and($activity->description)->toContain('during');
    });

    it('sets content_updated_at on upload', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $service = app(PhotoService::class);
        $photo = $service->upload(
            UploadedFile::fake()->image('test.jpg'),
            $property,
            $user
        );

        expect($photo->content_updated_at)->not->toBeNull();
    });

    it('marks photo as viewed by uploader on upload', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $service = app(PhotoService::class);
        $photo = $service->upload(
            UploadedFile::fake()->image('test.jpg'),
            $property,
            $user
        );

        $view = PhotoView::where('property_photo_id', $photo->id)
            ->where('user_id', $user->id)
            ->first();

        expect($view)->not->toBeNull();
    });

    it('sets content_updated_at on annotation save', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create(['content_updated_at' => null]);

        $service = app(AnnotationService::class);
        $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $user);

        expect($photo->fresh()->content_updated_at)->not->toBeNull();
    });

    it('marks photo as viewed by annotator on annotation save', function () {
        $user = User::factory()->internal()->create();
        $photo = PropertyPhoto::factory()->create();

        $service = app(AnnotationService::class);
        $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $user);

        $view = PhotoView::where('property_photo_id', $photo->id)
            ->where('user_id', $user->id)
            ->first();

        expect($view)->not->toBeNull();
    });
});
