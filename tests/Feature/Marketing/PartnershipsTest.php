<?php

use App\Models\MarketingStrategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('marketing.view');
});

// --- Authorization ---

it('requires authentication for partnerships page', function () {
    $this->get('/marketing/partnerships')
        ->assertRedirect('/login');
});

it('denies access without marketing.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/marketing/partnerships')
        ->assertForbidden();
});

it('grants access with marketing.view permission', function () {
    $this->actingAs($this->internalUser)
        ->get('/marketing/partnerships')
        ->assertSuccessful();
});

// --- Strategy Display ---

it('displays partnership strategies', function () {
    MarketingStrategy::factory()->partnerships()->create([
        'key' => 'sherwin_williams',
        'name' => 'Sherwin-Williams PRO+',
    ]);
    MarketingStrategy::factory()->partnerships()->create([
        'key' => 'home_inspectors',
        'name' => 'Home Inspector Referrals',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->assertSee('Home Inspector Referrals');
});

it('does not display strategies from other categories', function () {
    MarketingStrategy::factory()->paidAds()->create([
        'name' => 'Google Ads Campaign',
    ]);
    MarketingStrategy::factory()->partnerships()->create([
        'key' => 'yard_signs',
        'name' => 'Yard Signs & Door Hangers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->assertSee('Yard Signs')
        ->assertDontSee('Google Ads Campaign');
});

it('shows empty state when no partnership strategies exist', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->assertSee('No partnership strategies configured yet.');
});

it('displays all six partnership strategy types', function () {
    $strategies = [
        ['key' => 'sherwin_williams', 'name' => 'Sherwin-Williams PRO+'],
        ['key' => 'home_inspectors', 'name' => 'Home Inspector Referrals'],
        ['key' => 'hoa_property_managers', 'name' => 'HOA & Property Managers'],
        ['key' => 'vehicle_wraps', 'name' => 'Vehicle Wraps & QR Codes'],
        ['key' => 'yard_signs', 'name' => 'Yard Signs & Door Hangers'],
        ['key' => 'events', 'name' => 'Events & Home Shows'],
    ];

    foreach ($strategies as $data) {
        MarketingStrategy::factory()->partnerships()->create($data);
    }

    $component = Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships');

    foreach ($strategies as $data) {
        $component->assertSee($data['name']);
    }
});

// --- Toggle Strategy ---

it('allows toggling strategy status with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->partnerships()->active()->create([
        'key' => 'sherwin_williams',
        'name' => 'Sherwin-Williams PRO+',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('paused');
});

it('allows activating a paused partnership strategy', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->partnerships()->paused()->create([
        'key' => 'home_inspectors',
        'name' => 'Home Inspector Referrals',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('active');
});

it('denies toggling strategy without marketing.manage permission', function () {
    $strategy = MarketingStrategy::factory()->partnerships()->active()->create([
        'key' => 'sherwin_williams',
        'name' => 'Sherwin-Williams PRO+',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->call('toggleStrategy', $strategy->id)
        ->assertForbidden();
});

// --- Notes ---

it('saves notes for a strategy with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->partnerships()->create([
        'key' => 'sherwin_williams',
        'name' => 'Sherwin-Williams PRO+',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->set('notes.sherwin_williams', 'Met with store manager on Monday')
        ->call('saveNotes', 'sherwin_williams');

    expect($strategy->fresh()->notes)->toBe('Met with store manager on Monday');
});

it('denies saving notes without marketing.manage permission', function () {
    MarketingStrategy::factory()->partnerships()->create([
        'key' => 'sherwin_williams',
        'name' => 'Sherwin-Williams PRO+',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->set('notes.sherwin_williams', 'Unauthorized notes')
        ->call('saveNotes', 'sherwin_williams')
        ->assertForbidden();
});

it('loads existing notes on mount', function () {
    MarketingStrategy::factory()->partnerships()->create([
        'key' => 'events',
        'name' => 'Events & Home Shows',
        'notes' => 'Denver Home Show booth reserved',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->assertSet('notes.events', 'Denver Home Show booth reserved');
});

// --- Page Content ---

it('displays the correct heading and subheading', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.partnerships')
        ->assertSee('Partnerships')
        ->assertSee('Relationship-based and physical marketing strategies');
});
