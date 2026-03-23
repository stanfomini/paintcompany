<?php

use App\Models\MarketingStrategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('marketing.view');
});

// --- Authorization ---

it('requires authentication for paid ads page', function () {
    $this->get('/marketing/paid-ads')
        ->assertRedirect('/login');
});

it('denies access without marketing.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/marketing/paid-ads')
        ->assertForbidden();
});

it('grants access with marketing.view permission', function () {
    $this->actingAs($this->internalUser)
        ->get('/marketing/paid-ads')
        ->assertSuccessful();
});

// --- Strategy Display ---

it('displays paid ads strategies', function () {
    MarketingStrategy::factory()->paidAds()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);
    MarketingStrategy::factory()->paidAds()->create([
        'key' => 'geofencing',
        'name' => 'Geofencing Paint Stores',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSee('Google Local Services Ads')
        ->assertSee('Geofencing Paint Stores');
});

it('does not display strategies from other categories', function () {
    MarketingStrategy::factory()->automated()->create([
        'name' => 'Automated SMS Campaign',
    ]);
    MarketingStrategy::factory()->paidAds()->create([
        'key' => 'retargeting',
        'name' => 'Retargeting Pixels',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSee('Retargeting Pixels')
        ->assertDontSee('Automated SMS Campaign');
});

it('shows empty state when no paid ads strategies exist', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSee('No paid advertising strategies configured yet.');
});

it('shows correct status badges for strategies', function () {
    MarketingStrategy::factory()->paidAds()->active()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);
    MarketingStrategy::factory()->paidAds()->paused()->create([
        'key' => 'geofencing',
        'name' => 'Geofencing Paint Stores',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSee('Active')
        ->assertSee('Paused');
});

// --- Toggle Strategy ---

it('allows toggling strategy status with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->paidAds()->active()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('paused');
});

it('allows activating a paused strategy', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->paidAds()->paused()->create([
        'key' => 'retargeting',
        'name' => 'Retargeting Pixels',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('active');
});

it('denies toggling strategy without marketing.manage permission', function () {
    $strategy = MarketingStrategy::factory()->paidAds()->active()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->call('toggleStrategy', $strategy->id)
        ->assertForbidden();
});

// --- Notes ---

it('saves notes for a strategy with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->paidAds()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->set('notes.google_lsa', 'Testing notes for LSA')
        ->call('saveNotes', 'google_lsa');

    expect($strategy->fresh()->notes)->toBe('Testing notes for LSA');
});

it('denies saving notes without marketing.manage permission', function () {
    MarketingStrategy::factory()->paidAds()->create([
        'key' => 'google_lsa',
        'name' => 'Google Local Services Ads',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->set('notes.google_lsa', 'Unauthorized notes')
        ->call('saveNotes', 'google_lsa')
        ->assertForbidden();
});

it('loads existing notes on mount', function () {
    MarketingStrategy::factory()->paidAds()->create([
        'key' => 'geofencing',
        'name' => 'Geofencing Paint Stores',
        'notes' => 'Existing notes here',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSet('notes.geofencing', 'Existing notes here');
});

// --- Page Content ---

it('displays the correct heading and subheading', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.paid-ads')
        ->assertSee('Paid Advertising')
        ->assertSee('External platform guides with status tracking');
});
