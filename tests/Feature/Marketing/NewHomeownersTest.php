<?php

use App\Models\MarketingStrategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('marketing.view');
});

// --- Authorization ---

it('requires authentication for new homeowners page', function () {
    $this->get('/marketing/new-homeowners')
        ->assertRedirect('/login');
});

it('denies access without marketing.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/marketing/new-homeowners')
        ->assertForbidden();
});

it('grants access with marketing.view permission', function () {
    $this->actingAs($this->internalUser)
        ->get('/marketing/new-homeowners')
        ->assertSuccessful();
});

// --- Strategy Display ---

it('displays new homeowner strategies', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSee('New Homeowner Triggers');
});

it('does not display strategies from other categories', function () {
    MarketingStrategy::factory()->paidAds()->create([
        'name' => 'Google Ads Campaign',
    ]);
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSee('New Homeowner Triggers')
        ->assertDontSee('Google Ads Campaign');
});

it('shows empty state when no new homeowner strategies exist', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSee('No new homeowner strategies configured yet.');
});

// --- Toggle Strategy ---

it('allows toggling strategy status with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->newHomeowners()->active()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('paused');
});

it('denies toggling strategy without marketing.manage permission', function () {
    $strategy = MarketingStrategy::factory()->newHomeowners()->active()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->call('toggleStrategy', $strategy->id)
        ->assertForbidden();
});

// --- Notes ---

it('saves notes for a strategy with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->set('notes.new_homeowner_triggers', 'PropertyRadar setup complete')
        ->call('saveNotes', 'new_homeowner_triggers');

    expect($strategy->fresh()->notes)->toBe('PropertyRadar setup complete');
});

it('denies saving notes without marketing.manage permission', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->set('notes.new_homeowner_triggers', 'Unauthorized')
        ->call('saveNotes', 'new_homeowner_triggers')
        ->assertForbidden();
});

it('loads existing notes on mount', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
        'notes' => 'PropertyRadar monitoring active',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSet('notes.new_homeowner_triggers', 'PropertyRadar monitoring active');
});

// --- Manual Stats ---

it('saves monthly metrics with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->set('postcardsSent', '150')
        ->set('responses', '12')
        ->set('leadsCreated', '5')
        ->set('monthlyCost', '299.50')
        ->call('saveStats');

    $strategy->refresh();

    expect($strategy->getConfig('stats.postcards_sent'))->toBe(150)
        ->and($strategy->getConfig('stats.responses'))->toBe(12)
        ->and($strategy->getConfig('stats.leads_created'))->toBe(5)
        ->and($strategy->getConfig('stats.monthly_cost'))->toBe(299.50);
});

it('denies saving stats without marketing.manage permission', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->set('postcardsSent', '100')
        ->call('saveStats')
        ->assertForbidden();
});

it('loads existing stats on mount', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
        'configuration' => [
            'stats' => [
                'postcards_sent' => 200,
                'responses' => 15,
                'leads_created' => 8,
                'monthly_cost' => 350.00,
            ],
        ],
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSet('postcardsSent', '200')
        ->assertSet('responses', '15')
        ->assertSet('leadsCreated', '8')
        ->assertSet('monthlyCost', '350');
});

it('validates stats are numeric', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->set('postcardsSent', 'not-a-number')
        ->call('saveStats')
        ->assertHasErrors(['postcardsSent']);
});

// --- Page Content ---

it('displays the correct heading and subheading', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSee('New Homeowners')
        ->assertSee('Target homeowners within 60 days of purchase');
});

it('displays PropertyRadar setup information', function () {
    MarketingStrategy::factory()->newHomeowners()->create([
        'key' => 'new_homeowner_triggers',
        'name' => 'New Homeowner Triggers',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.new-homeowners')
        ->assertSee('PropertyRadar');
});
