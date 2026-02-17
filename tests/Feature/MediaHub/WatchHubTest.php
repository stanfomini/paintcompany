<?php

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('watch hub is accessible without auth', function () {
    $this->get('/watch')->assertOk();
});

it('watch hub shows only public active channels', function () {
    $publicActive = VideoChannel::factory()->public()->create(['name' => 'Public Active Channel']);
    $privateChannel = VideoChannel::factory()->private()->create(['name' => 'Private Channel']);
    $inactiveChannel = VideoChannel::factory()->inactive()->create(['name' => 'Inactive Channel']);

    $this->get('/watch')
        ->assertOk()
        ->assertSee('Public Active Channel')
        ->assertDontSee('Private Channel')
        ->assertDontSee('Inactive Channel');
});

it('watch hub hides private channels', function () {
    VideoChannel::factory()->private()->create(['name' => 'Secret Videos']);

    $this->get('/watch')
        ->assertOk()
        ->assertDontSee('Secret Videos');
});

it('watch hub hides inactive channels', function () {
    VideoChannel::factory()->public()->inactive()->create(['name' => 'Archived Channel']);

    $this->get('/watch')
        ->assertOk()
        ->assertDontSee('Archived Channel');
});

it('channel page shows published videos', function () {
    $channel = VideoChannel::factory()->public()->create();
    $readyVideo = VideoRecording::factory()->ready()->create(['title' => 'Ready Video']);
    $unpublishedVideo = VideoRecording::factory()->ready()->create(['title' => 'Draft Video']);

    VideoChannelItem::factory()->published()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $readyVideo->id,
    ]);

    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $unpublishedVideo->id,
        'published_at' => null,
    ]);

    $this->get("/watch/{$channel->slug}")
        ->assertOk()
        ->assertSee('Ready Video')
        ->assertDontSee('Draft Video');
});

it('channel page returns 404 for private channel', function () {
    $channel = VideoChannel::factory()->private()->create();

    $this->get("/watch/{$channel->slug}")
        ->assertNotFound();
});

it('channel page returns 404 for inactive channel', function () {
    $channel = VideoChannel::factory()->public()->inactive()->create();

    $this->get("/watch/{$channel->slug}")
        ->assertNotFound();
});

it('channel page returns 404 for nonexistent slug', function () {
    $this->get('/watch/nonexistent-slug-12345')
        ->assertNotFound();
});

it('video player page shows video', function () {
    $channel = VideoChannel::factory()->public()->create();
    $video = VideoRecording::factory()->ready()->create(['title' => 'My Test Video']);

    VideoChannelItem::factory()->published()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    $this->get("/watch/{$channel->slug}/{$video->id}")
        ->assertOk()
        ->assertSee('My Test Video')
        ->assertSee('data-track-id');
});

it('video player returns 404 if video not in channel', function () {
    $channel = VideoChannel::factory()->public()->create();
    $video = VideoRecording::factory()->ready()->create();

    // Video exists but is NOT in this channel
    $this->get("/watch/{$channel->slug}/{$video->id}")
        ->assertNotFound();
});

it('video player returns 404 for video not published in channel', function () {
    $channel = VideoChannel::factory()->public()->create();
    $video = VideoRecording::factory()->ready()->create();

    // Video is in channel but not published
    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
        'published_at' => null,
    ]);

    $this->get("/watch/{$channel->slug}/{$video->id}")
        ->assertNotFound();
});
