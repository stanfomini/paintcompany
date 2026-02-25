<?php

use App\Models\LandingPageVideoSection;
use App\Models\LandingPageVideoSectionItem;
use App\Models\User;
use App\Models\VideoRecording;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('blocks landing page videos tab for non-admin users', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('media.landing-page-videos')
        ->assertForbidden();
});

it('shows landing page videos tab for media.manage users', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->assertSuccessful();
});

it('toggles section active state', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    expect($section->is_active)->toBeFalse();

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('toggleSection', $section->id);

    $section->refresh();
    expect($section->is_active)->toBeTrue();
});

it('adds video to section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $admin->id,
        'visibility' => 'public',
    ]);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('openPicker', $section->id)
        ->call('addVideo', $video->id);

    expect($section->videos()->count())->toBe(1);
    expect($section->videos()->first()->id)->toBe($video->id);
});

it('limits section to maximum 3 videos', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    $videos = VideoRecording::factory()->ready()->count(4)->create([
        'user_id' => $admin->id,
        'visibility' => 'public',
    ]);

    $component = Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('openPicker', $section->id);

    foreach ($videos->take(3) as $video) {
        $component->call('addVideo', $video->id);
    }

    expect($section->videos()->count())->toBe(3);

    // Try to add 4th
    $component->call('addVideo', $videos->last()->id);

    // Still 3
    expect($section->videos()->count())->toBe(3);
});

it('removes video from section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $admin->id,
        'visibility' => 'public',
    ]);

    LandingPageVideoSectionItem::create([
        'landing_page_video_section_id' => $section->id,
        'video_recording_id' => $video->id,
        'sort_order' => 1,
    ]);

    expect($section->videos()->count())->toBe(1);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('removeVideo', $section->id, $video->id);

    expect($section->videos()->count())->toBe(0);
});

it('reorders videos within section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    $videos = VideoRecording::factory()->ready()->count(3)->create([
        'user_id' => $admin->id,
        'visibility' => 'public',
    ]);

    foreach ($videos as $i => $video) {
        LandingPageVideoSectionItem::create([
            'landing_page_video_section_id' => $section->id,
            'video_recording_id' => $video->id,
            'sort_order' => $i + 1,
        ]);
    }

    // Reverse order
    $newOrder = $videos->reverse()->pluck('id')->values()->toArray();

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('reorderVideos', $section->id, $newOrder);

    $items = LandingPageVideoSectionItem::where('landing_page_video_section_id', $section->id)
        ->orderBy('sort_order')
        ->pluck('video_recording_id')
        ->toArray();

    expect($items)->toBe($newOrder);
});

it('renders active sections with videos on public landing page', function () {
    $user = User::factory()->internal()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $section->update(['is_active' => true, 'subtitle' => 'Learn painting techniques']);

    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'How to Paint a Wall',
        'visibility' => 'public',
    ]);

    LandingPageVideoSectionItem::create([
        'landing_page_video_section_id' => $section->id,
        'video_recording_id' => $video->id,
        'sort_order' => 1,
    ]);

    Livewire::test('public.landing-page')
        ->assertSee('Tutorials')
        ->assertSee('Learn painting techniques')
        ->assertSee('How to Paint a Wall');
});

it('does not render inactive sections on public landing page', function () {
    $user = User::factory()->internal()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    // Section is inactive by default

    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'Hidden Tutorial Video',
        'visibility' => 'public',
    ]);

    LandingPageVideoSectionItem::create([
        'landing_page_video_section_id' => $section->id,
        'video_recording_id' => $video->id,
        'sort_order' => 1,
    ]);

    Livewire::test('public.landing-page')
        ->assertDontSee('Hidden Tutorial Video');
});
