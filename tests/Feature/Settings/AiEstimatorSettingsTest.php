<?php

use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->agentUser = User::factory()->agent()->create();
    $this->customerUser = User::factory()->customer()->create();
});

it('internal users can access ai estimator settings page', function () {
    $this->actingAs($this->internalUser)
        ->get(route('settings.ai-estimator'))
        ->assertSuccessful()
        ->assertSee('AI Estimator')
        ->assertSee('Background Instructions');
});

it('agent users cannot access ai estimator settings page', function () {
    $this->actingAs($this->agentUser)
        ->get(route('settings.ai-estimator'))
        ->assertForbidden();
});

it('customer users cannot access ai estimator settings page', function () {
    $this->actingAs($this->customerUser)
        ->get(route('settings.ai-estimator'))
        ->assertForbidden();
});

it('guests are redirected to login', function () {
    $this->get(route('settings.ai-estimator'))
        ->assertRedirect(route('login'));
});

it('internal users can save ai estimator instructions', function () {
    $instructions = "We primarily do residential interior painting.\nDefault brand is Sherwin-Williams Duration.";

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.ai-estimator')
        ->set('instructions', $instructions)
        ->call('save')
        ->assertDispatched('notify');

    $settings = CompanySetting::getInstance();
    expect($settings->estimate_builder_instructions)->toBe($instructions);
});

it('loads existing instructions on mount', function () {
    CompanySetting::getInstance()->update([
        'estimate_builder_instructions' => 'Existing instructions here',
    ]);
    CompanySetting::clearInstanceCache();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.ai-estimator')
        ->assertSet('instructions', 'Existing instructions here');
});

it('saves empty instructions as null', function () {
    CompanySetting::getInstance()->update([
        'estimate_builder_instructions' => 'Old instructions',
    ]);
    CompanySetting::clearInstanceCache();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.ai-estimator')
        ->set('instructions', '')
        ->call('save')
        ->assertDispatched('notify');

    $settings = CompanySetting::first();
    expect($settings->estimate_builder_instructions)->toBeNull();
});

it('validates instructions max length', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.ai-estimator')
        ->set('instructions', str_repeat('a', 10001))
        ->call('save')
        ->assertHasErrors(['instructions' => 'max']);
});
