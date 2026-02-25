<?php

use App\Models\MediaStar;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can star a video recording', function () {
    $user = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();

    $result = $recording->toggleStar($user);
    expect($result)->toBeTrue()
        ->and($recording->isStarredBy($user))->toBeTrue()
        ->and(MediaStar::count())->toBe(1);
});

it('can unstar a video recording', function () {
    $user = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();

    $recording->toggleStar($user); // star
    $result = $recording->toggleStar($user); // unstar

    expect($result)->toBeFalse()
        ->and($recording->isStarredBy($user))->toBeFalse()
        ->and(MediaStar::count())->toBe(0);
});

it('can star a property photo', function () {
    $user = User::factory()->create();
    $photo = PropertyPhoto::factory()->create();

    $result = $photo->toggleStar($user);
    expect($result)->toBeTrue()
        ->and($photo->isStarredBy($user))->toBeTrue();
});

it('maintains separate stars per user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();

    $recording->toggleStar($user1);
    $recording->toggleStar($user2);

    expect($recording->isStarredBy($user1))->toBeTrue()
        ->and($recording->isStarredBy($user2))->toBeTrue()
        ->and(MediaStar::count())->toBe(2);

    $recording->toggleStar($user1); // unstar user1 only
    expect($recording->isStarredBy($user1))->toBeFalse()
        ->and($recording->isStarredBy($user2))->toBeTrue();
});

it('maintains separate stars per model type', function () {
    $user = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    $photo = PropertyPhoto::factory()->create();

    $recording->toggleStar($user);
    $photo->toggleStar($user);

    expect($recording->isStarredBy($user))->toBeTrue()
        ->and($photo->isStarredBy($user))->toBeTrue()
        ->and(MediaStar::count())->toBe(2);
});

it('can query starred items with scope', function () {
    $user = User::factory()->create();
    $starred = VideoRecording::factory()->ready()->create();
    $unstarred = VideoRecording::factory()->ready()->create();

    $starred->toggleStar($user);

    $results = VideoRecording::starredBy($user)->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($starred->id);
});

it('can query starred photos with scope', function () {
    $user = User::factory()->create();
    $starred = PropertyPhoto::factory()->create();
    PropertyPhoto::factory()->create(); // not starred

    $starred->toggleStar($user);

    expect(PropertyPhoto::starredBy($user)->count())->toBe(1);
});

it('has stars morphMany relationship', function () {
    $user = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    $recording->toggleStar($user);

    expect($recording->stars)->toHaveCount(1)
        ->and($recording->stars->first()->user_id)->toBe($user->id);
});

it('prevents duplicate stars for same user and model', function () {
    $user = User::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();

    // Create directly to test unique constraint
    MediaStar::create([
        'user_id' => $user->id,
        'starrable_type' => VideoRecording::class,
        'starrable_id' => $recording->id,
    ]);

    // toggleStar should handle this gracefully (finds existing and deletes)
    $result = $recording->toggleStar($user);
    expect($result)->toBeFalse()
        ->and(MediaStar::count())->toBe(0);
});
