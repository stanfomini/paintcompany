<?php

use App\Models\MarketingStrategy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('marketing.view');
});

// --- Authorization ---

it('requires authentication', function () {
    $this->get('/marketing/automated')
        ->assertRedirect('/login');
});

it('denies access without marketing.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/marketing/automated')
        ->assertForbidden();
});

it('grants access with marketing.view permission', function () {
    $this->actingAs($this->internalUser)
        ->get('/marketing/automated')
        ->assertSuccessful();
});

// --- Page Content ---

it('displays the page heading and subheading', function () {
    $this->actingAs($this->internalUser)
        ->get('/marketing/automated')
        ->assertSee('Automated Campaigns')
        ->assertSee('Automations that run when jobs complete');
});

it('displays all four automated strategies', function () {
    MarketingStrategy::factory()->automated()->create(['key' => 'neighborhood_campaigns', 'name' => 'Neighborhood Campaigns']);
    MarketingStrategy::factory()->automated()->create(['key' => 'referral_program', 'name' => 'Referral Program']);
    MarketingStrategy::factory()->automated()->create(['key' => 'review_requests', 'name' => 'Review Requests']);
    MarketingStrategy::factory()->automated()->create(['key' => 'sms_reengagement', 'name' => 'SMS Re-engagement']);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->assertSee('Neighborhood Campaigns')
        ->assertSee('Referral Program')
        ->assertSee('Review Requests')
        ->assertSee('SMS Re-engagement');
});

it('shows empty state when no automated strategies exist', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->assertSee('No automated strategies found');
});

it('shows correct status badges for strategies', function () {
    MarketingStrategy::factory()->automated()->active()->create(['key' => 'neighborhood_campaigns', 'name' => 'Neighborhood Campaigns']);
    MarketingStrategy::factory()->automated()->paused()->create(['key' => 'referral_program', 'name' => 'Referral Program']);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->assertSee('Neighborhood Campaigns')
        ->assertSee('Referral Program');
});

// --- Toggle Strategy ---

it('allows toggling an active strategy to paused with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->active()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('paused');
});

it('allows toggling a paused strategy to active with marketing.manage permission', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->paused()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->call('toggleStrategy', $strategy->id);

    expect($strategy->fresh()->status)->toBe('active');
});

it('denies toggling strategy without marketing.manage permission', function () {
    $strategy = MarketingStrategy::factory()->automated()->active()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->call('toggleStrategy', $strategy->id)
        ->assertForbidden();
});

// --- Save Configuration ---

it('saves neighborhood campaigns configuration', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('neighborhoodConfig.radius_miles', 2.5)
        ->set('neighborhoodConfig.delay_days_after_completion', 5)
        ->set('neighborhoodConfig.notes', 'Test notes')
        ->call('saveStrategyConfig', 'neighborhood_campaigns');

    $strategy->refresh();
    expect($strategy->configuration)->toMatchArray([
        'radius_miles' => 2.5,
        'delay_days_after_completion' => 5,
        'notes' => 'Test notes',
    ]);
});

it('saves referral program configuration', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->create([
        'key' => 'referral_program',
        'name' => 'Referral Program',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('referralConfig.referrer_reward', '$200 cash')
        ->set('referralConfig.referee_discount', '$200 off')
        ->set('referralConfig.sms_template', 'Custom referral template')
        ->set('referralConfig.delay_days_after_completion', 10)
        ->call('saveStrategyConfig', 'referral_program');

    $strategy->refresh();
    expect($strategy->configuration)->toMatchArray([
        'referrer_reward' => '$200 cash',
        'referee_discount' => '$200 off',
        'sms_template' => 'Custom referral template',
        'delay_days_after_completion' => 10,
    ]);
});

it('saves review requests configuration', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->create([
        'key' => 'review_requests',
        'name' => 'Review Requests',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('reviewConfig.sms_template', 'Custom review template')
        ->set('reviewConfig.google_review_url', 'https://g.page/my-biz')
        ->set('reviewConfig.yelp_review_url', 'https://www.yelp.com/biz/my-biz')
        ->set('reviewConfig.delay_days_after_completion', 5)
        ->call('saveStrategyConfig', 'review_requests');

    $strategy->refresh();
    expect($strategy->configuration)->toMatchArray([
        'sms_template' => 'Custom review template',
        'google_review_url' => 'https://g.page/my-biz',
        'yelp_review_url' => 'https://www.yelp.com/biz/my-biz',
        'delay_days_after_completion' => 5,
    ]);
});

it('saves sms reengagement configuration', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    $strategy = MarketingStrategy::factory()->automated()->create([
        'key' => 'sms_reengagement',
        'name' => 'SMS Re-engagement',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('reengagementConfig.months_since_last_job', 36)
        ->set('reengagementConfig.sms_template', 'Custom reengagement template')
        ->set('reengagementConfig.exclude_active_deals', false)
        ->call('saveStrategyConfig', 'sms_reengagement');

    $strategy->refresh();
    expect($strategy->configuration)->toMatchArray([
        'months_since_last_job' => 36,
        'sms_template' => 'Custom reengagement template',
        'exclude_active_deals' => false,
    ]);
});

it('denies saving configuration without marketing.manage permission', function () {
    MarketingStrategy::factory()->automated()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->call('saveStrategyConfig', 'neighborhood_campaigns')
        ->assertForbidden();
});

// --- Validation ---

it('validates neighborhood config radius is required and numeric', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->automated()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('neighborhoodConfig.radius_miles', '')
        ->call('saveStrategyConfig', 'neighborhood_campaigns')
        ->assertHasErrors(['neighborhoodConfig.radius_miles']);
});

it('validates referral sms template is required', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->automated()->create([
        'key' => 'referral_program',
        'name' => 'Referral Program',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('referralConfig.sms_template', '')
        ->call('saveStrategyConfig', 'referral_program')
        ->assertHasErrors(['referralConfig.sms_template']);
});

it('validates review google url must be valid url', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->automated()->create([
        'key' => 'review_requests',
        'name' => 'Review Requests',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('reviewConfig.google_review_url', 'not-a-url')
        ->call('saveStrategyConfig', 'review_requests')
        ->assertHasErrors(['reviewConfig.google_review_url']);
});

it('validates reengagement months must be at least 1', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->automated()->create([
        'key' => 'sms_reengagement',
        'name' => 'SMS Re-engagement',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->set('reengagementConfig.months_since_last_job', 0)
        ->call('saveStrategyConfig', 'sms_reengagement')
        ->assertHasErrors(['reengagementConfig.months_since_last_job']);
});

// --- Loads existing config from database ---

it('loads existing configuration from database on mount', function () {
    $this->internalUser->givePermissionTo('marketing.manage');

    MarketingStrategy::factory()->automated()->create([
        'key' => 'neighborhood_campaigns',
        'name' => 'Neighborhood Campaigns',
        'configuration' => [
            'radius_miles' => 5.0,
            'delay_days_after_completion' => 10,
            'notes' => 'Previously saved notes',
        ],
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('pages::marketing.automated')
        ->assertSet('neighborhoodConfig.radius_miles', 5.0)
        ->assertSet('neighborhoodConfig.delay_days_after_completion', 10)
        ->assertSet('neighborhoodConfig.notes', 'Previously saved notes');
});
