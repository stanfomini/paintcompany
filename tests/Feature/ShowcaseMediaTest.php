<?php

use App\Models\ShowcaseMedia;
use App\Models\VideoRecording;

it('creates showcase media with video reference', function () {
    $video = VideoRecording::factory()->ready()->create();

    $media = ShowcaseMedia::create([
        'slug' => 'color-tutorial',
        'label' => 'Color Tutorial Video',
        'video_recording_id' => $video->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    expect($media)->toBeInstanceOf(ShowcaseMedia::class)
        ->and($media->slug)->toBe('color-tutorial')
        ->and($media->label)->toBe('Color Tutorial Video')
        ->and($media->video_recording_id)->toBe($video->id)
        ->and($media->is_active)->toBeTrue()
        ->and($media->sort_order)->toBe(1)
        ->and($media->video)->toBeInstanceOf(VideoRecording::class)
        ->and($media->video->id)->toBe($video->id);
});

it('finds active showcase media by slug', function () {
    $media = ShowcaseMedia::factory()->create([
        'slug' => 'test-media',
        'is_active' => true,
    ]);

    $found = ShowcaseMedia::findBySlug('test-media');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($media->id);
});

it('returns null for inactive showcase media slug', function () {
    ShowcaseMedia::factory()->inactive()->create([
        'slug' => 'inactive-media',
    ]);

    $found = ShowcaseMedia::findBySlug('inactive-media');

    expect($found)->toBeNull();
});

it('resolves public URL from video recording', function () {
    $video = VideoRecording::factory()->ready()->create([
        'share_token' => 'test-share-token-12345678901234',
    ]);

    $media = ShowcaseMedia::factory()->create([
        'video_recording_id' => $video->id,
    ]);

    $url = $media->getPublicUrl();

    // Should resolve to the shareable URL (route-based)
    expect($url)->not->toBeNull()
        ->and($url)->toContain('test-share-token-12345678901234');
});

it('returns null public URL when no video attached', function () {
    $media = ShowcaseMedia::factory()->create([
        'video_recording_id' => null,
    ]);

    expect($media->getPublicUrl())->toBeNull();
});

it('uses factory withVideo state correctly', function () {
    $media = ShowcaseMedia::factory()->withVideo()->create();

    expect($media->video)->not->toBeNull()
        ->and($media->video->status)->toBe('ready');
});

it('scopes to active records only', function () {
    ShowcaseMedia::factory()->create(['is_active' => true]);
    ShowcaseMedia::factory()->inactive()->create();

    expect(ShowcaseMedia::active()->count())->toBe(1);
});
