<?php

use App\Models\User;
use App\Models\VideoRecording;
use App\Models\VideoView;

describe('Video View Tracking', function () {
    it('returns false for hasUnviewedContent when no content_updated_at', function () {
        $user = User::factory()->create();
        $recording = VideoRecording::factory()->ready()->create();

        // Must update after creation since the booted creating hook sets content_updated_at
        $recording->updateQuietly(['content_updated_at' => null]);
        $recording->refresh();

        expect($recording->hasUnviewedContent($user))->toBeFalse();
    });

    it('returns true for hasUnviewedContent when content exists but no view', function () {
        $user = User::factory()->create();
        $recording = VideoRecording::factory()->ready()->create();

        // content_updated_at is set automatically on creating
        expect($recording->content_updated_at)->not->toBeNull();
        expect($recording->hasUnviewedContent($user))->toBeTrue();
    });

    it('returns false for hasUnviewedContent after marking viewed', function () {
        $user = User::factory()->create();
        $recording = VideoRecording::factory()->ready()->create();

        // Mark as viewed
        VideoView::markContentViewed($recording, $user);

        expect($recording->hasUnviewedContent($user))->toBeFalse();
    });

    it('returns true for hasUnviewedContent when content updated after viewing', function () {
        $user = User::factory()->create();
        $recording = VideoRecording::factory()->ready()->create();

        // Mark as viewed
        VideoView::markContentViewed($recording, $user);

        // Update content_updated_at to be after the view
        $recording->update(['content_updated_at' => now()->addMinute()]);

        expect($recording->fresh()->hasUnviewedContent($user))->toBeTrue();
    });

    it('creates a view with content_view source via markContentViewed', function () {
        $user = User::factory()->create();
        $recording = VideoRecording::factory()->ready()->create();

        $view = VideoView::markContentViewed($recording, $user);

        expect($view)->toBeInstanceOf(VideoView::class);
        expect($view->video_recording_id)->toBe($recording->id);
        expect($view->viewer_id)->toBe($user->id);
        expect($view->source)->toBe('content_view');
    });

    it('sets content_updated_at on creating', function () {
        $recording = VideoRecording::factory()->create();

        expect($recording->content_updated_at)->not->toBeNull();
    });

    it('sets content_updated_at when status changes to ready', function () {
        $recording = VideoRecording::factory()->create(['status' => 'uploading']);
        $originalTimestamp = $recording->content_updated_at;

        // Wait a moment then update status to ready
        sleep(1);
        $recording->update(['status' => 'ready']);
        $recording->refresh();

        expect($recording->content_updated_at->greaterThan($originalTimestamp))->toBeTrue();
    });

    it('does not update content_updated_at for non-status changes', function () {
        $recording = VideoRecording::factory()->ready()->create();
        $originalTimestamp = $recording->content_updated_at->toISOString();

        $recording->update(['title' => 'New Title']);
        $recording->refresh();

        expect($recording->content_updated_at->toISOString())->toBe($originalTimestamp);
    });
});
