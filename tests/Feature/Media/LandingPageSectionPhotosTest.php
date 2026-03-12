<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\LandingPageSectionPhoto;
use App\Models\LandingPageVideoSection;
use App\Models\LandingPageVideoSectionItem;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('blocks non-admin users from managing section photos', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('media.landing-page-videos')
        ->assertForbidden();
});

it('allows media.manage users to access the component', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->assertSuccessful();
});

it('uploads photos to a section', function () {
    Storage::fake('s3');

    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    $file = UploadedFile::fake()->image('test-photo.jpg', 800, 600);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('openPhotoUploader', $section->id)
        ->set('uploadPhotos', [$file])
        ->set('uploadCaption', 'Test caption')
        ->call('uploadSectionPhotos');

    expect($section->photos()->count())->toBe(1);

    $photo = $section->photos()->first();
    expect($photo->caption)->toBe('Test caption');
    expect($photo->original_filename)->toBe('test-photo.jpg');
    expect($photo->disk)->toBe(config('filesystems.default'));
});

it('selects photo from deal and adds to section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    $photo = PropertyPhoto::factory()->reference()->create([
        'property_id' => $property->id,
        'path' => 'photos/test.jpg',
        'thumbnail_path' => 'photos/test-thumb.jpg',
    ]);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('openPhotoPicker', $section->id)
        ->call('selectDeal', $deal->id)
        ->call('addPhotoFromDeal', $photo->id);

    expect($section->photos()->count())->toBe(1);

    $sectionPhoto = $section->photos()->first();
    expect($sectionPhoto->path)->toBe('photos/test.jpg');
    expect($sectionPhoto->property_photo_id)->toBe($photo->id);
    expect($sectionPhoto->deal_id)->toBe($deal->id);
});

it('enforces maximum 6 photos per section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    // Create 6 photos
    for ($i = 1; $i <= 6; $i++) {
        LandingPageSectionPhoto::create([
            'landing_page_video_section_id' => $section->id,
            'path' => "photos/test-{$i}.jpg",
            'sort_order' => $i,
        ]);
    }

    expect($section->photos()->count())->toBe(6);

    // Try to add 7th via deal photo
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    $photo = PropertyPhoto::factory()->reference()->create([
        'property_id' => $property->id,
        'path' => 'photos/extra.jpg',
    ]);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('openPhotoPicker', $section->id)
        ->call('selectDeal', $deal->id)
        ->call('addPhotoFromDeal', $photo->id)
        ->assertDispatched('notify');

    // Still 6
    expect($section->photos()->count())->toBe(6);
});

it('removes photo from section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    $photo = LandingPageSectionPhoto::create([
        'landing_page_video_section_id' => $section->id,
        'path' => 'photos/test.jpg',
        'sort_order' => 1,
    ]);

    expect($section->photos()->count())->toBe(1);

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('removeSectionPhoto', $section->id, $photo->id);

    expect($section->photos()->count())->toBe(0);
});

it('toggles show_on_proposal on section', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();

    expect($section->show_on_proposal)->toBeFalse();

    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('toggleShowOnProposal', $section->id);

    $section->refresh();
    expect($section->show_on_proposal)->toBeTrue();

    // Toggle back off
    Livewire::actingAs($admin)
        ->test('media.landing-page-videos')
        ->call('toggleShowOnProposal', $section->id);

    $section->refresh();
    expect($section->show_on_proposal)->toBeFalse();
});

it('renders photos in sections on public landing page', function () {
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $section->update(['is_active' => true]);

    LandingPageSectionPhoto::create([
        'landing_page_video_section_id' => $section->id,
        'path' => 'photos/landing-test.jpg',
        'caption' => 'Beautiful living room',
        'sort_order' => 1,
    ]);

    Livewire::test('public.landing-page')
        ->assertSee('Tutorials')
        ->assertSee('Beautiful living room');
});

it('renders sections with both videos and photos on public landing page', function () {
    $user = User::factory()->internal()->create();
    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $section->update(['is_active' => true]);

    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'Test Tutorial Video',
        'visibility' => 'public',
    ]);

    LandingPageVideoSectionItem::create([
        'landing_page_video_section_id' => $section->id,
        'video_recording_id' => $video->id,
        'sort_order' => 1,
    ]);

    LandingPageSectionPhoto::create([
        'landing_page_video_section_id' => $section->id,
        'path' => 'photos/mixed-test.jpg',
        'caption' => 'Mixed section photo',
        'sort_order' => 1,
    ]);

    Livewire::test('public.landing-page')
        ->assertSee('Tutorials')
        ->assertSee('Test Tutorial Video')
        ->assertSee('Mixed section photo');
});

it('shows sections marked show_on_proposal on proposal page', function () {
    CompanySetting::create(['company_name' => 'Test Company']);

    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $section->update(['is_active' => true, 'show_on_proposal' => true]);

    LandingPageSectionPhoto::create([
        'landing_page_video_section_id' => $section->id,
        'path' => 'photos/proposal-photo.jpg',
        'caption' => 'Proposal section photo',
        'sort_order' => 1,
    ]);

    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);

    Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
        ->assertSee('Tutorials')
        ->assertSee('Proposal section photo');
});

it('hides sections not marked show_on_proposal from proposal page', function () {
    CompanySetting::create(['company_name' => 'Test Company']);

    $section = LandingPageVideoSection::where('slug', 'tutorials')->first();
    $section->update(['is_active' => true, 'show_on_proposal' => false]);

    LandingPageSectionPhoto::create([
        'landing_page_video_section_id' => $section->id,
        'path' => 'photos/hidden-photo.jpg',
        'caption' => 'Hidden proposal photo',
        'sort_order' => 1,
    ]);

    $estimate = Estimate::factory()->sent()->create([
        'public_token' => Str::random(64),
        'public_token_expires_at' => now()->addDays(30),
    ]);

    Livewire::test('public.estimate-proposal', ['token' => $estimate->public_token])
        ->assertDontSee('Hidden proposal photo');
});
