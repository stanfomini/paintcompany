<?php

use App\Models\FbLandingPageOffer;
use App\Models\FbLandingPageView;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

it('blocks unauthorized users', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertForbidden();
});

it('renders for authorized users', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertSuccessful()
        ->assertSee('Facebook Offer Campaigns');
});

it('shows empty state when no offers exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertSee('No campaigns yet');
});

it('lists existing offers', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $offer = FbLandingPageOffer::factory()->create(['title' => 'Spring Painting Special']);

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertSee('Spring Painting Special');
});

it('opens the create form', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertSet('showForm', false)
        ->call('create')
        ->assertSet('showForm', true)
        ->assertSet('editingId', null)
        ->assertSet('title', '');
});

it('creates a new offer', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->set('title', 'Summer Deal')
        ->set('headline', 'Save Big This Summer')
        ->set('bullets', ['Free estimates', 'Licensed painters'])
        ->set('cta_text', 'Get Started')
        ->call('save')
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('fb_landing_page_offers', [
        'title' => 'Summer Deal',
        'headline' => 'Save Big This Summer',
        'cta_text' => 'Get Started',
    ]);

    $offer = FbLandingPageOffer::where('title', 'Summer Deal')->first();
    expect($offer->slug)->toStartWith('summer-deal-');
    expect($offer->offer_bullets)->toBe(['Free estimates', 'Licensed painters']);
});

it('validates required fields on save', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->set('title', '')
        ->set('headline', '')
        ->call('save')
        ->assertHasErrors(['title', 'headline']);
});

it('edits an existing offer', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $offer = FbLandingPageOffer::factory()->create([
        'title' => 'Old Title',
        'headline' => 'Old Headline',
    ]);

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('edit', $offer->id)
        ->assertSet('showForm', true)
        ->assertSet('editingId', $offer->id)
        ->assertSet('title', 'Old Title')
        ->assertSet('headline', 'Old Headline')
        ->set('title', 'New Title')
        ->set('headline', 'New Headline')
        ->call('save')
        ->assertSet('showForm', false);

    $offer->refresh();
    expect($offer->title)->toBe('New Title');
    expect($offer->headline)->toBe('New Headline');
});

it('toggles offer active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $offer = FbLandingPageOffer::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('toggleActive', $offer->id);

    $offer->refresh();
    expect($offer->is_active)->toBeFalse();
});

it('deletes an offer', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $offer = FbLandingPageOffer::factory()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('delete', $offer->id);

    $this->assertDatabaseMissing('fb_landing_page_offers', ['id' => $offer->id]);
});

it('adds and removes bullets', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->assertCount('bullets', 1)
        ->call('addBullet')
        ->assertCount('bullets', 2)
        ->call('addBullet')
        ->assertCount('bullets', 3)
        ->call('removeBullet', 1)
        ->assertCount('bullets', 2);
});

it('prevents removing the last bullet', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->assertCount('bullets', 1)
        ->call('removeBullet', 0)
        ->assertCount('bullets', 1);
});

it('cancels the form and resets fields', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->set('title', 'Something')
        ->set('headline', 'Test')
        ->call('cancelForm')
        ->assertSet('showForm', false)
        ->assertSet('title', '')
        ->assertSet('headline', '')
        ->assertSet('editingId', null);
});

it('filters out empty bullets on save', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->set('title', 'Test Offer')
        ->set('headline', 'Test Headline')
        ->set('bullets', ['Real bullet', '', 'Another bullet', ''])
        ->call('save')
        ->assertSet('showForm', false);

    $offer = FbLandingPageOffer::where('title', 'Test Offer')->first();
    expect($offer->offer_bullets)->toBe(['Real bullet', 'Another bullet']);
});

it('displays view stats for offers', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $offer = FbLandingPageOffer::factory()->create(['title' => 'Stats Offer']);

    // Create 3 views, 1 converted
    FbLandingPageView::factory()->count(2)->forOffer($offer)->create();
    FbLandingPageView::factory()->converted()->forOffer($offer)->create();

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->assertSee('Stats Offer')
        ->assertSee('3')     // total views
        ->assertSee('conversions');
});

it('saves with a video recording attached', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create(['available_in_hub' => true]);

    Livewire::actingAs($user)
        ->test('media.offer-manager')
        ->call('create')
        ->set('title', 'Video Offer')
        ->set('headline', 'Watch Our Video')
        ->set('bullets', ['Great service'])
        ->set('video_recording_id', $video->id)
        ->call('save')
        ->assertSet('showForm', false);

    $offer = FbLandingPageOffer::where('title', 'Video Offer')->first();
    expect($offer->video_recording_id)->toBe($video->id);
});
